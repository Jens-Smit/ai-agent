<?php
// src/Service/Workflow/WorkflowExecutor.php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\UserDocumentRepository;
use App\Service\AgentStatusService;
use App\Service\Workflow\Executor\AnalysisAndCommunicationTrait;
use App\Service\Workflow\Executor\ToolExecutionTrait;
use App\Tool\CompanyCareerContactFinderTool;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WorkflowExecutor
{
    use ToolExecutionTrait;
    use AnalysisAndCommunicationTrait;

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
        private CompanyCareerContactFinderTool $contactFinderTool,
    ) {}

    /**
     * Führt einen kompletten Workflow aus
     */
    public function executeWorkflow(Workflow $workflow, ?User $user = null): void
    {
        $this->throttle();
        $workflow->setStatus('running');
        $this->em->flush();

        $context = [];

        foreach ($workflow->getSteps() as $step) {
            $this->throttle();

            // ✅ NEU: Lade Context auch von bereits abgeschlossenen Steps
            if ($step->getStatus() === 'completed') {
                $stepKey = 'step_' . $step->getStepNumber();
                $result = $step->getResult();
                
                // Flatten Tool-Results (wie bei der Ausführung)
                if (isset($result['result']) && count($result) === 2 && isset($result['tool'])) {
                    $context[$stepKey] = ['result' => $result['result']];
                } else {
                    $context[$stepKey] = ['result' => $result];
                }
                
                $this->logger->debug('Loaded context from completed step', [
                    'step' => $step->getStepNumber(),
                    'context_key' => $stepKey
                ]);
                
                continue; // Überspringe Ausführung
            }

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Führe Step %d aus: %s', $step->getStepNumber(), $step->getDescription())
            );

            try {
                $result = $this->executeStep($step, $context, $workflow->getSessionId(), $user);

                $stepKey = 'step_' . $step->getStepNumber();

                // ✅ VERBESSERTES Context-Handling für strukturierte Tool-Results
                if (isset($result['tool'])) {
                    // Tool-Result mit strukturiertem Output
                    if (isset($result['result']) && is_array($result['result'])) {
                        // Wenn result ein Array ist, speichere es direkt
                        $context[$stepKey] = ['result' => $result['result']];
                    } elseif (isset($result['result']) && is_string($result['result'])) {
                        // String-Result: speichere als-ist
                        $context[$stepKey] = ['result' => $result['result']];
                    } else {
                        // Fallback: Entferne 'tool' key und speichere Rest
                        $resultData = $result;
                        unset($resultData['tool']);
                        $context[$stepKey] = ['result' => $resultData];
                    }
                } else {
                    // Kein Tool-Result (Analysis, Decision, etc.)
                    $context[$stepKey] = ['result' => $result];
                }

                $this->logger->debug('Step result stored in context', [
                    'step' => $step->getStepNumber(),
                    'context_key' => $stepKey,
                    'result_keys' => is_array($result) ? array_keys($result) : 'scalar',
                    'context_preview' => is_array($context[$stepKey]['result']) 
                        ? array_keys($context[$stepKey]['result']) 
                        : substr((string)$context[$stepKey]['result'], 0, 100)
                ]);

                $step->setResult($result);
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());

                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen', $step->getStepNumber())
                );

                // Pause bei E-Mail-Bestätigung
                if ($step->requiresConfirmation() && $step->getStatus() === 'pending_confirmation') {
                    $workflow->setStatus('waiting_confirmation');
                    $workflow->setCurrentStep($step->getStepNumber());
                    $this->em->flush();

                    $this->statusService->addStatus(
                        $workflow->getSessionId(),
                        sprintf('⏸️ Warte auf Bestätigung: %s', $step->getDescription())
                    );

                    return;
                }

            } catch (\Exception $e) {
                $this->handleStepFailure($workflow, $step, $e);
                return;
            }

            $this->em->flush();
        }

        $workflow->setStatus('completed');
        $workflow->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '🎉 Workflow erfolgreich abgeschlossen!'
        );
    }

    /**
     * Führt einen einzelnen Step aus
     */
    private function executeStep(WorkflowStep $step, array $context, string $sessionId, ?User $user): array
    {
        $maxRetries = 3;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $result = match ($step->getStepType()) {
                    'tool_call' => $this->executeToolCall($step, $context, $sessionId, $user),
                    'analysis' => $this->executeAnalysis($step, $context, $sessionId),
                    'decision' => $this->executeDecision($step, $context, $sessionId),
                    'notification' => $this->executeNotification($step, $context, $sessionId),
                    default => throw new \RuntimeException("Unknown step type: {$step->getStepType()}")
                };

                return $result;

            } catch (\Throwable $e) {
                $attempt++;
                $lastException = $e;

                if (!$this->isTransientError($e->getMessage()) || $attempt >= $maxRetries) {
                    throw $e;
                }

                $this->logger->warning('Step failed, retrying', [
                    'step' => $step->getStepNumber(),
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                $backoff = min(pow(2, $attempt - 1) * 5, 30);
                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('⚠️ Retry %d/%d (warte %ds): %s', $attempt, $maxRetries, $backoff, substr($e->getMessage(), 0, 80))
                );

                sleep($backoff);
            }
        }

        throw $lastException ?? new \RuntimeException('Step failed after retries');
    }

    /**
     * Bestätigt einen E-Mail-Step und sendet die E-Mail
     */
    public function confirmAndSendEmail(Workflow $workflow, WorkflowStep $step, ?User $user): void
    {
        if ($step->getToolName() !== 'send_email' && $step->getToolName() !== 'SendMailTool') {
            throw new \RuntimeException('Step is not an email step');
        }

        if ($step->getStatus() !== 'pending_confirmation') {
            throw new \RuntimeException('Email is not pending confirmation');
        }

        try {
            $result = $this->executeSendEmail($step, $workflow->getSessionId(), $user);
            
            $step->setResult($result);
            $step->setStatus('completed');
            $step->setCompletedAt(new \DateTimeImmutable());
            
            $workflow->setStatus('running');
            $workflow->setCurrentStep(null);
            
            $this->em->flush();

            // Setze Workflow fort
            $this->executeWorkflow($workflow, $user);
            
        } catch (\Exception $e) {
            $this->handleStepFailure($workflow, $step, $e);
            throw $e;
        }
    }

    /**
     * Lehnt eine E-Mail ab
     */
    public function rejectEmail(Workflow $workflow, WorkflowStep $step): void
    {
        if ($step->getStatus() !== 'pending_confirmation') {
            throw new \RuntimeException('Email is not pending confirmation');
        }

        $step->setStatus('rejected');
        $step->setErrorMessage('Email rejected by user');
        
        $workflow->setStatus('cancelled');
        $workflow->setCurrentStep(null);
        
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '❌ E-Mail abgelehnt - Workflow abgebrochen'
        );
    }

    /**
     * Setzt einen Workflow nach Bestätigung fort (für Nicht-E-Mail-Steps)
     */
    public function confirmAndContinue(Workflow $workflow, WorkflowStep $step, ?User $user = null): void
    {
        $step->setStatus('completed');
        $step->setCompletedAt(new \DateTimeImmutable());

        $workflow->setStatus('running');
        $workflow->setCurrentStep(null);
        $this->em->flush();

        $this->executeWorkflow($workflow, $user);
    }

    /**
     * Behandelt Step-Fehler
     */
    private function handleStepFailure(Workflow $workflow, WorkflowStep $step, \Exception $e): void
    {
        $this->logger->error('Step failed', [
            'workflow_id' => $workflow->getId(),
            'step' => $step->getStepNumber(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $step->setStatus('failed');
        $step->setErrorMessage($e->getMessage());
        $workflow->setStatus('failed');
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            sprintf('❌ Step %d fehlgeschlagen: %s', $step->getStepNumber(), $e->getMessage())
        );
    }

    /**
     * Throttling für API-Calls
     */
    private function throttle(): void
    {
        usleep(500000); // 500ms Pause
    }
}