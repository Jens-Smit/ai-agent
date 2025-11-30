<?php
// src/Service/Workflow/EnhancedWorkflowExecutor.php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\UserDocumentRepository;
use App\Service\AgentStatusService;
use App\Service\Workflow\Context\ContextResolver;
use App\Service\Workflow\Executor\AnalysisAndCommunicationTrait;
use App\Service\Workflow\Executor\SmartDecisionTrait;
use App\Service\Workflow\Executor\SmartRetryTrait;
use App\Service\Workflow\Executor\ToolExecutionTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EnhancedWorkflowExecutor
{
    use ToolExecutionTrait;
    use AnalysisAndCommunicationTrait;
    use SmartRetryTrait;
    use SmartDecisionTrait;

    private ContextResolver $contextResolver;
    private int $agentFailureCount = 0;
    private bool $useFlashLite = false;
    private ?AgentInterface $flashLiteAgent = null;

    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        #[Autowire(service: 'ai.traceable_platform.gemini')]
        private PlatformInterface $platform,
        private EntityManagerInterface $em,
        private UserDocumentRepository $documentRepo,
        private AgentStatusService $statusService,
        private LoggerInterface $logger,
        private \App\Tool\CompanyCareerContactFinderTool $contactFinderTool,
    ) {
        $this->contextResolver = new ContextResolver($this->logger);
    }

    public function executeWorkflow(Workflow $workflow, ?User $user = null): void
    {
        $workflow->setStatus('running');
        $this->em->flush();

        $context = [];
        $failedSteps = 0;

        foreach ($workflow->getSteps() as $step) {
            $stepKey = 'step_' . $step->getStepNumber();

            if ($step->getStatus() === 'completed') {
                $context[$stepKey] = ['result' => $step->getResult()];
                continue;
            }

            if ($this->isRetryStep($step) && $this->shouldSkipStep($step, $context)) {
                $this->logger->info('Skipping retry step - previous attempt successful', [
                    'step' => $step->getStepNumber()
                ]);
                
                $result = $this->copyLastSuccessfulJobResult($step, $context);
                
                $context[$stepKey] = ['result' => $result];
                $step->setResult($result);
                $step->setStatus('skipped');
                $step->setCompletedAt(new \DateTimeImmutable());
                
                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('⏭️ Step %d übersprungen', $step->getStepNumber())
                );
                
                $this->em->flush();
                continue;
            }

            if ($step->getStepType() === 'decision') {
                $description = strtolower($step->getDescription());
                if (str_contains($description, 'finale') || 
                    str_contains($description, 'wähle besten') ||
                    str_contains($description, 'aus allen versuchen')) {
                    
                    $result = $this->findBestJobFromRetries($context, $step->getStepNumber());
                    
                    $context[$stepKey] = ['result' => $result];
                    $step->setResult($result);
                    $step->setStatus('completed');
                    $step->setCompletedAt(new \DateTimeImmutable());
                    
                    $this->statusService->addStatus(
                        $workflow->getSessionId(),
                        sprintf('✅ Step %d: Bester Job ausgewählt', $step->getStepNumber())
                    );
                    
                    $this->em->flush();
                    continue;
                }
            }

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Führe Step %d aus: %s', $step->getStepNumber(), $step->getDescription())
            );

            try {
                // 🔧 FIX: executeStepWithRecovery aktualisiert jetzt den Context direkt
                $result = $this->executeStepWithRecovery($step, $context, $workflow->getSessionId(), $user);
                
                if ($this->isEmptyResult($result)) {
                    $this->logger->warning('Step returned empty result, attempting recovery', [
                        'step' => $step->getStepNumber()
                    ]);

                    $result = $this->retryStepWithEnhancedContext($step, $context, $workflow->getSessionId(), $user);
                }

                // Speichere Result
                $context[$stepKey] = ['result' => $result];
                $step->setResult($result);
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());

                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen', $step->getStepNumber())
                );

            } catch (\Exception $e) {
                $failedSteps++;

                $this->logger->error('Step execution failed', [
                    'step' => $step->getStepNumber(),
                    'error' => $e->getMessage()
                ]);

                if ($failedSteps < 3 && $this->canContinueWithoutStep($step)) {
                    $step->setStatus('skipped');
                    $step->setErrorMessage('Skipped due to error: ' . $e->getMessage());
                    
                    $context[$stepKey] = ['result' => $this->createPlaceholderResult($step)];
                    
                    $this->em->flush();
                    continue;
                }

                $this->handleStepFailure($workflow, $step, $e);
                return;
            }

            $this->em->flush();
        }

        $workflow->setStatus('completed');
        $workflow->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * 🔧 FIX: executeStepWithRecovery aktualisiert jetzt Context für generate_search_variants
     */
    private function executeStepWithRecovery(
        WorkflowStep $step,
        array &$context, // 🔧 FIX: Übergabe als Referenz!
        string $sessionId,
        ?User $user
    ): array {
        $maxRetries = 2;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // 🔧 FIX: Spezial-Handling für generate_search_variants
                if ($step->getStepType() === 'tool_call' && 
                    $step->getToolName() === 'generate_search_variants') {
                    return $this->executeGenerateSearchVariants($step, $context, $sessionId);
                }

                return $this->executeStep($step, $context, $sessionId, $user);
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $this->logger->warning('Step failed, retrying', [
                        'step' => $step->getStepNumber(),
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);

                    sleep(2 * $attempt);
                }
            }
        }

        throw $lastException;
    }

    /**
     * 🔧 FIX: Generiert Search-Varianten UND aktualisiert Context DIREKT
     */
    private function executeGenerateSearchVariants(
        WorkflowStep $step,
        array &$context, // 🔧 FIX: Referenz!
        string $sessionId
    ): array {
        // Hole Parameter
        $params = $this->contextResolver->resolveAll($step->getToolParameters(), $context);

        $this->logger->info('Generating search variants', [
            'params' => $params
        ]);

        // 🔧 FIX: generateAndStoreSearchVariants aktualisiert $context direkt (durch Referenz)
        $this->generateAndStoreSearchVariants($params, $context);

        // Logging
        $variantCount = $context['search_variants_count'] ?? 0;
        $firstVariant = $context['search_variants_list'][0] ?? null;

        $this->logger->info('Search variants generated and stored in context', [
            'variant_count' => $variantCount,
            'first_variant' => $firstVariant,
            'context_keys' => array_keys($context)
        ]);

        $this->statusService->addStatus(
            $sessionId,
            sprintf('🔍 %d Suchvarianten generiert', $variantCount)
        );

        // Return für Step-Result
        return [
            'tool' => 'generate_search_variants',
            'variants_generated' => $variantCount,
            'first_variant' => $firstVariant
        ];
    }

    private function retryStepWithEnhancedContext(
        WorkflowStep $step,
        array $context,
        string $sessionId,
        ?User $user
    ): array {
        $this->logger->info('Retrying step with enhanced context', [
            'step' => $step->getStepNumber()
        ]);

        $enhancedPrompt = sprintf(
            "%s\n\nWICHTIG: Vorherige Versuche waren leer. Bitte gib KONKRETE Werte zurück!",
            $step->getDescription()
        );

        $step->setDescription($enhancedPrompt);

        return $this->executeStep($step, $context, $sessionId, $user);
    }

    private function isEmptyResult(mixed $result): bool
    {
        if (!is_array($result)) {
            return false;
        }

        $nonNullValues = array_filter($result, fn($v) => $v !== null && $v !== '');
        
        return empty($nonNullValues);
    }

    private function canContinueWithoutStep(WorkflowStep $step): bool
    {
        $optionalSteps = [
            'user_document_list',
            'company_career_contact_finder'
        ];

        return in_array($step->getToolName(), $optionalSteps);
    }

    private function createPlaceholderResult(WorkflowStep $step): array
    {
        $format = $step->getExpectedOutputFormat();

        if (!$format || !isset($format['fields'])) {
            return ['skipped' => true];
        }

        $result = [];
        foreach ($format['fields'] as $field => $type) {
            $result[$field] = $this->getDefaultValueForType($type);
        }

        return $result;
    }

    private function getDefaultValueForType(string $type): mixed
    {
        return match($type) {
            'string' => '',
            'integer', 'number' => 0,
            'boolean' => false,
            'array' => [],
            default => null
        };
    }

    /**
     * 🔧 FIX: executeStep mit besserer Platzhalter-Auflösung
     */
    private function executeStep(WorkflowStep $step, array $context, string $sessionId, ?User $user): array
    {
        // Löse Platzhalter in Tool-Parametern VOR Ausführung auf
        if ($step->getStepType() === 'tool_call') {
            $originalParams = $step->getToolParameters();
            $resolvedParams = $this->contextResolver->resolveAll($originalParams, $context);

            // Prüfe auf unaufgelöste Platzhalter
            if ($this->contextResolver->hasUnresolvedPlaceholders($resolvedParams)) {
                $unresolved = $this->contextResolver->findUnresolvedPlaceholders($resolvedParams);
                
                // 🔧 FIX: Bessere Fehlermeldung mit Context-Info
                $this->logger->error('Unresolved placeholders detected', [
                    'step' => $step->getStepNumber(),
                    'unresolved' => $unresolved,
                    'available_context_keys' => array_keys($context),
                    'original_params' => $originalParams,
                    'resolved_params' => $resolvedParams
                ]);
                
                throw new \RuntimeException(
                    'Cannot execute step - unresolved placeholders: ' . implode(', ', $unresolved)
                );
            }

            // Update Step mit aufgelösten Parametern
            $step->setToolParameters($resolvedParams);
            
            $this->logger->debug('Resolved tool parameters', [
                'step' => $step->getStepNumber(),
                'original' => $originalParams,
                'resolved' => $resolvedParams
            ]);
        }

        return match ($step->getStepType()) {
            'tool_call' => $this->executeToolCall($step, $context, $sessionId, $user),
            'analysis' => $this->executeAnalysis($step, $context, $sessionId),
            'decision' => $this->executeSmartDecision($step, $context, $sessionId),
            'notification' => $this->executeNotification($step, $context, $sessionId),
            default => throw new \RuntimeException("Unknown step type: {$step->getStepType()}")
        };
    }

    private function handleStepFailure(Workflow $workflow, WorkflowStep $step, \Exception $e): void
    {
        $step->setStatus('failed');
        $step->setErrorMessage($e->getMessage());
        $workflow->setStatus('failed');
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            sprintf('❌ Workflow fehlgeschlagen bei Step %d', $step->getStepNumber())
        );
    }
}