<?php
// src/Service/Workflow/WorkflowExecutor.php
// GEÄNDERTE VERSION mit User-Persistence im Workflow

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
    use SmartRetryTrait;
    use SmartDecisionTrait; // ✅ NEU

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
        private CompanyCareerContactFinderTool $contactFinderTool,
    ) {}

    /**
     * ✅ VERBESSERT: Workflow-Execution mit User-Persistence und korrekter Pausierung
     */
    public function executeWorkflow(Workflow $workflow, ?User $user = null): void
    {
        $this->throttle();

        // 1. Initiales Setup und User-ID Speicherung
        if ($user && !$workflow->getUserId()) {
            $workflow->setUserId($user->getId()); // Füge diese Zeile hinzu, um die User-ID im Workflow zu speichern
            $this->logger->info('Storing user context in workflow', [
                'workflow_id' => $workflow->getId(),
                'user_id' => $user->getId()
            ]);
        }

        $workflow->setStatus('running');
        $this->em->flush();

        $context = [];

        foreach ($workflow->getSteps() as $step) {
            $this->throttle();
            
            // 2. Kontext laden & Pausierte/Fertige Steps behandeln
            $stepKey = 'step_' . $step->getStepNumber();

            // Lade Context von bereits abgeschlossenen Steps
            if ($step->getStatus() === 'completed' || $step->getStatus() === 'pending_confirmation') {
                $result = $step->getResult();
                
                // Generisches Kontext-Parsing (kann beibehalten werden)
                if (isset($result['result']) && count($result) === 2 && isset($result['tool'])) {
                    $context[$stepKey] = ['result' => $result['result']];
                } else {
                    $context[$stepKey] = ['result' => $result];
                }
                
                $this->logger->debug('Loaded context from completed/pending step', [
                    'step' => $step->getStepNumber(),
                    'context_key' => $stepKey
                ]);
                
                // ✅ NEU & KRITISCH: Bei pending_confirmation sofort pausieren und beenden, falls es sich um diesen Step handelt.
                // Dies behebt den Fehler, dass ein "pending_confirmation" Step sofort als "completed" markiert wird.
                if ($step->getStatus() === 'pending_confirmation') {
                    $this->pauseWorkflowForConfirmation($workflow, $step);
                    return; // Beende die Ausführung, warte auf externe Bestätigung
                }
                
                continue; // Springe zum nächsten Step, falls bereits completed
            }

            // Überspringe failed Steps beim Neustart (Logik beibehalten)
            if ($step->getStatus() === 'failed') {
                $this->logger->info('Resetting failed step for retry', [
                    'step' => $step->getStepNumber(),
                    'previous_error' => $step->getErrorMessage()
                ]);
                
                $step->setStatus('pending');
                $step->setErrorMessage(null);
                $this->em->flush();
            }

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Führe Step %d aus: %s', $step->getStepNumber(), $step->getDescription())
            );

            // 3. Step ausführen und Status setzen
            try {
                // Führt den Step aus, z.B. ToolExecutionTrait::executeToolCall
                $result = $this->executeStep($step, $context, $workflow->getSessionId(), $user);
                
                // Kontext-Handling (Logik beibehalten)
                // ... (Context-Zuweisung wie im Original-Code) ...
                
                // Vereinfachte Kontexteinbindung (um Code-Redundanz zu vermeiden)
                if (isset($result['tool']) && isset($result['result']) && is_array($result['result'])) {
                    $context[$stepKey] = ['result' => $result['result']];
                } elseif (isset($result['tool'])) {
                    $resultData = $result;
                    unset($resultData['tool']);
                    $context[$stepKey] = ['result' => $resultData];
                } else {
                    $context[$stepKey] = ['result' => $result];
                }

                $this->logger->debug('Step result stored in context', [
                    'step' => $step->getStepNumber(),
                    'context_key' => $stepKey,
                    'result_keys' => is_array($result) ? array_keys($result) : 'scalar'
                ]);

                // WICHTIG: Wenn executeStep den Status auf 'pending_confirmation' gesetzt hat (wie bei send_email), 
                // darf er hier NICHT überschrieben werden!
                if ($step->getStatus() !== 'pending_confirmation') {
                    $step->setResult($result);
                    $step->setStatus('completed');
                    $step->setCompletedAt(new \DateTimeImmutable());
                } else {
                    // Wenn der Step auf pending_confirmation gesetzt wurde, werden Result und CompletedAt nicht gesetzt
                    $step->setResult($result); // Speichert zumindest das Ergebnis der Vorbereitung (e.g. email_details)
                }

                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen (oder zur Bestätigung bereit)', $step->getStepNumber())
                );
                
                // 4. Pausieren, wenn Bestätigung erforderlich ist
                if ($step->requiresConfirmation()) { // Hier fragen wir nur ab, ob das requiresConfirmation Flag gesetzt wurde
                    $this->pauseWorkflowForConfirmation($workflow, $step);
                    return; // KRITISCH: Beende die Ausführung, warte auf externe Bestätigung
                }

            } catch (\Exception $e) {
                $this->handleStepFailure($workflow, $step, $e);
                return;
            }

            $this->em->flush();
        }

        // 5. Workflow-Abschluss
        $workflow->setStatus('completed');
        $workflow->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '🎉 Workflow erfolgreich abgeschlossen!'
        );
    }
    
    /**
     * Helper-Methode für die Pausierungslogik
     */
    private function pauseWorkflowForConfirmation(Workflow $workflow, WorkflowStep $step): void
    {
        $emailDetails = $step->getEmailDetails();
        
        // Prüfe ob User-Kontext erforderlich ist (z.B. für E-Mail-Anhänge)
        if ($emailDetails && ($emailDetails['requires_user_context'] ?? false)) {
            $workflow->setStatus('waiting_user_input');
            $workflow->requireUserInteraction(
                'User-Authentifizierung erforderlich um E-Mail-Details zu laden und zu versenden',
                [
                    'step_id' => $step->getId(),
                    'step_number' => $step->getStepNumber(),
                    'action_required' => 'authenticate',
                    'reason' => 'email_details_incomplete'
                ]
            );
        } else {
            // Normale Bestätigung (E-Mail vorbereitet und User ist bereits bekannt)
            $workflow->setStatus('waiting_confirmation');
        }
        
        $workflow->setCurrentStep($step->getStepNumber());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            sprintf('⏸️ Warte auf Bestätigung: %s', $step->getDescription())
        );
    }

    /**
     * ✅ VERBESSERT: Step-Execution mit robustem User-Handling
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

                // ✅ NEU: Keine Retries bei User-Kontext-Fehlern
                if (str_contains($e->getMessage(), 'User context') || 
                    str_contains($e->getMessage(), 'not authenticated')) {
                    throw $e; // Sofort werfen ohne Retry
                }

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
     * ✅ VERBESSERT: E-Mail-Bestätigung mit User-Reload
     */
    public function confirmAndSendEmail(Workflow $workflow, WorkflowStep $step, ?User $user): void
    {
        if ($step->getToolName() !== 'send_email' && $step->getToolName() !== 'SendMailTool') {
            throw new \RuntimeException('Step is not an email step');
        }

        if (!$step->requiresConfirmation()) {
            throw new \RuntimeException('Email is not pending confirmation');
        }

        // ✅ NEU: Lade User aus Workflow wenn nicht übergeben
        if (!$user) {
            $emailDetails = $step->getEmailDetails();
            if ($emailDetails && isset($emailDetails['_user_id'])) {
                $user = $this->em->getRepository(User::class)->find($emailDetails['_user_id']);
                
                if (!$user) {
                    throw new \RuntimeException('User not found - cannot send email');
                }
                
                $this->logger->info('User context restored for email sending', [
                    'user_id' => $user->getId(),
                    'workflow_id' => $workflow->getId()
                ]);
            }
        }

        if (!$user) {
            throw new \RuntimeException('User context required for sending email');
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
     * Setzt einen Workflow nach Bestätigung fort
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
     * ✅ VERBESSERT: Bessere Fehlerbehandlung
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
        
        // ✅ NEU: Workflow nicht auf "failed" setzen bei User-Kontext-Fehlern
        if (str_contains($e->getMessage(), 'User context') || 
            str_contains($e->getMessage(), 'not authenticated')) {
            $workflow->setStatus('waiting_user_input');
            $workflow->requireUserInteraction(
                'User-Authentifizierung erforderlich',
                [
                    'step_id' => $step->getId(),
                    'step_number' => $step->getStepNumber(),
                    'error' => $e->getMessage()
                ]
            );
        } else {
            $workflow->setStatus('failed');
        }
        
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            sprintf('❌ Step %d fehlgeschlagen: %s', $step->getStepNumber(), $e->getMessage())
        );
    }

    private function throttle(): void
    {
        usleep(500000);
    }
}