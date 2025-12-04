<?php
// src/Service/Workflow/WorkflowExecutor.php
// 🔧 FIXED: E-Mail-Confirmation funktioniert jetzt korrekt!

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\UserDocumentRepository;
use App\Service\AgentStatusService;
use App\Service\Workflow\Context\ContextResolver;
use App\Tool\CompanyCareerContactFinderTool;
use App\Tool\JobSearchTool;
use App\Tool\SendMailTool;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WorkflowExecutor
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private UserDocumentRepository $documentRepo,
        private AgentStatusService $statusService,
        private LoggerInterface $logger,
        private ContextResolver $contextResolver,
        private CompanyCareerContactFinderTool $contactFinderTool,
        private JobSearchTool $jobSearchTool,
         private SendMailTool $sendMailTool
    ) {}

    // ========================================
    // PUBLIC API
    // ========================================

    public function executeWorkflow(Workflow $workflow, User $user): void
    {
        $this->logger->info('Executing workflow', [
            'workflow_id' => $workflow->getId(),
            'user_id' => $user->getId()
        ]);

        $workflow->setStatus('running');
        $this->em->flush();

        $context = [];

        foreach ($workflow->getSteps() as $step) {
            $stepKey = 'step_' . $step->getStepNumber();

            // Überspringe bereits abgeschlossene Steps
            if ($step->getStatus() === 'completed') {
                $context[$stepKey] = ['result' => $step->getResult()];
                continue;
            }
            
            // Step wartet auf Confirmation
            if ($step->getStatus() === 'pending_confirmation') {
                $this->pauseForConfirmation($workflow, $step);
                return;
            }

            // Führe Step aus
            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Step %d: %s', $step->getStepNumber(), $step->getDescription())
            );

            try {
                $result = $this->executeStep($step, $context, $user);
                
                // Speichere Result
                $context[$stepKey] = ['result' => $result];
                $step->setResult($result);
                
                // 🔧 FIX: Check ob Step JETZT auf Confirmation wartet
                if ($step->getStatus() === 'pending_confirmation') {
                    // Step hat sich selbst pausiert (z.B. E-Mail)
                    $this->pauseForConfirmation($workflow, $step);
                    return;
                }
                
                // Step normal abschließen
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());

                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen', $step->getStepNumber())
                );

            } catch (\Exception $e) {
                $this->handleStepFailure($workflow, $step, $e);
                return;
            }

            $this->em->flush();
        }

        // Workflow erfolgreich abgeschlossen
        $workflow->setStatus('completed');
        $workflow->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '🎉 Workflow erfolgreich abgeschlossen!'
        );
    }

    public function confirmAndContinue(Workflow $workflow, WorkflowStep $step, User $user): void
    {
        if ($step->getStatus() !== 'pending_confirmation') {
            throw new \RuntimeException('Step wartet nicht auf Confirmation');
        }

        $this->logger->info('Confirming step', [
            'step_id' => $step->getId(),
            'step_number' => $step->getStepNumber()
        ]);

        $step->setStatus('completed');
        $step->setCompletedAt(new \DateTimeImmutable());
        
        $workflow->setStatus('running');
        $workflow->setCurrentStep(null);
        
        $this->em->flush();

        // Setze Workflow fort
        $this->executeWorkflow($workflow, $user);
    }

    public function confirmAndSendEmail(Workflow $workflow, WorkflowStep $step, User $user): void
    {
        if (!in_array($step->getToolName(), ['send_email', 'SendMailTool'])) {
            throw new \RuntimeException('Step ist kein E-Mail-Step');
        }

        if ($step->getStatus() !== 'pending_confirmation') {
            throw new \RuntimeException('E-Mail wartet nicht auf Confirmation');
        }

        $this->logger->info('Sending confirmed email', [
            'step_id' => $step->getId(),
            'user_id' => $user->getId()
        ]);

        try {
            $result = $this->sendEmail($step, $user);
            
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

    // ========================================
    // STEP EXECUTION
    // ========================================

    private function executeStep(WorkflowStep $step, array &$context, User $user): array
    {
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return match ($step->getStepType()) {
                    'tool_call' => $this->executeToolCall($step, $context, $user),
                    'analysis' => $this->executeAnalysis($step, $context),
                    'decision' => $this->executeDecision($step, $context),
                    'notification' => $this->executeNotification($step, $context),
                    default => throw new \RuntimeException("Unbekannter Step-Type: {$step->getStepType()}")
                };

            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt < $maxRetries && $this->isRetriable($e)) {
                    $this->logger->warning('Step failed, retrying', [
                        'step' => $step->getStepNumber(),
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                    
                    sleep(pow(2, $attempt));
                    continue;
                }
                
                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * 🔧 FIXED: E-Mail-Tool Handling mit korrektem Flush
     */
    private function executeToolCall(WorkflowStep $step, array $context, User $user): array
    {
        $toolName = $step->getToolName();
        $parameters = $this->contextResolver->resolveAll($step->getToolParameters(), $context);
        
        if ($this->contextResolver->hasUnresolvedPlaceholders($parameters)) {
            $unresolved = $this->contextResolver->findUnresolvedPlaceholders($parameters);
            throw new \RuntimeException(
                'Ungelöste Placeholders: ' . implode(', ', $unresolved)
            );
        }

        $this->logger->info('Executing tool', [
            'tool' => $toolName,
            'parameters' => $parameters
        ]);

        // 🔧 FIX: E-Mail-Tools immer vorbereiten und pausieren
        if (in_array($toolName, ['send_email', 'SendMailTool'])) {
            $result = $this->prepareEmailConfirmation($step, $parameters, $user);
            // WICHTIG: Status wird bereits in prepareEmailConfirmation gesetzt
            $this->em->flush(); // ← FIX: Persistiere Email-Details sofort!
            return $result;
        }

        return match ($toolName) {
            'company_career_contact_finder' => $this->executeContactFinder($step, $parameters),
            'job_search' => $this->executeJobSearch($step, $parameters),
            default => $this->executeGenericTool($toolName, $parameters)
        };
    }

    private function executeAnalysis(WorkflowStep $step, array $context): array
    {
        $expectedFormat = $step->getExpectedOutputFormat();
        
        if (!$expectedFormat || !isset($expectedFormat['fields'])) {
            $prompt = sprintf(
                'Analysiere: %s\n\nDaten: %s',
                $step->getDescription(),
                json_encode($context, JSON_UNESCAPED_UNICODE)
            );
            
            $messages = new MessageBag(Message::ofUser($prompt));
            $result = $this->agent->call($messages);
            
            return ['analysis' => $result->getContent()];
        }

        $prompt = sprintf(
            '%s

                WICHTIG: Antworte NUR mit JSON:
                ```json
                {
                %s
                }
                ```
                Daten: %s',
            $step->getDescription(),
            implode(",\n", array_map(fn($k) => "  \"$k\": \"wert\"", array_keys($expectedFormat['fields']))),
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    
        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);
        
        return $this->extractStructuredJson($result->getContent(), array_keys($expectedFormat['fields']));
    }

    private function executeDecision(WorkflowStep $step, array $context): array
    {
        $description = strtolower($step->getDescription());
        
        // Check: Job-Auswahl?
        $isJobSelection = (str_contains($description, 'job') || str_contains($description, 'stelle')) && 
                         (str_contains($description, 'wähl') || str_contains($description, 'best') || str_contains($description, 'auswahl'));
        
        if ($isJobSelection) {
            return $this->handleJobSelection($step, $context);
        }

        // Normale Decision
        return $this->executeAnalysis($step, $context);
    }

    private function executeNotification(WorkflowStep $step, array $context): array
    {
        $message = $this->contextResolver->resolveAll($step->getDescription(), $context);
        
        $this->statusService->addStatus(
            $step->getWorkflow()->getSessionId(),
            '📧 ' . $message
        );

        return [
            'notification_sent' => true,
            'message' => $message
        ];
    }

    // ========================================
    // SPEZIAL-HANDLING
    // ========================================

    private function executeJobSearch(WorkflowStep $step, array $parameters): array
    {
        $what = $parameters['what'] ?? $parameters['was'] ?? null;
        $where = $parameters['where'] ?? $parameters['wo'] ?? null;
        
        if (!$what || !is_string($what)) {
            throw new \InvalidArgumentException("JobSearchTool Parameter 'what' fehlt");
        }

        $this->logger->info('🔍 Executing JobSearchTool', [
            'what' => $what,
            'where' => $where
        ]);

        // Tool aufrufen
        $apiResult = ($this->jobSearchTool)($what, $where);

        // Agent-Zusammenfassung erstellen
        $prompt = sprintf(
            'Fasse die folgenden Stellenausschreibungen kurz zusammen. Liste Titel, Firma und Veröffentlichungsdatum auf: %s',
            json_encode($apiResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $agentSummary = $this->agent->call($messages);

        return [
            'tool' => 'job_search',
            'status' => 'success',
            'data' => $apiResult,
            'summary' => $agentSummary->getContent()
        ];
    }

    private function handleJobSelection(WorkflowStep $step, array $context): array
    {
        $this->logger->info('🎯 Starting job selection', [
            'context_keys' => array_keys($context)
        ]);

        $jobs = $this->collectJobsFromContext($context);
        
        $this->logger->info('📊 Job collection result', [
            'job_count' => count($jobs),
            'jobs_preview' => array_slice($jobs, 0, 2)
        ]);
        
        if (empty($jobs)) {
            throw new \RuntimeException('Keine Jobs zur Auswahl verfügbar');
        }

        // Speichere Jobs für spätere Auswahl
        $step->setResult([
            'status' => 'awaiting_selection',
            'available_jobs' => $jobs,
            'job_count' => count($jobs)
        ]);
        
        $step->setStatus('pending_confirmation');
        $this->em->flush();

        return [
            'status' => 'awaiting_user_selection',
            'available_jobs' => $jobs
        ];
    }

    /**
     * 🔧 FIXED: E-Mail-Vorbereitung mit Status-Setzung
     */
    private function prepareEmailConfirmation(WorkflowStep $step, array $parameters, User $user): array
    {
         $recipient = $parameters['to'] ?? $parameters['recipient'] ?? 'Unbekannt';
        // Wenn immer noch "Unbekannt", versuche aus Context zu laden
        if ($recipient === 'Unbekannt' || empty($recipient)) {
            $this->logger->error('No recipient found in parameters, checking for fallback');
            
            
        }
        $subject = $parameters['subject'] ?? 'Kein Betreff';
        $body = $parameters['body'] ?? '';
        
        $attachmentIds = $this->parseAttachments($parameters['attachments'] ?? []);
        $attachmentDetails = $this->loadAttachmentDetails($attachmentIds, $user);
       
        $emailDetails = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'body_preview' => mb_substr(strip_tags($body), 0, 200),
            'attachments' => $attachmentDetails,
            'attachment_count' => count($attachmentDetails),
            'ready_to_send' => true,
            '_original_params' => $parameters,
            '_user_id' => $user->getId()
        ];

        // 🔧 FIX: Setze Status UND Details
        $step->setEmailDetails($emailDetails);
        $step->setStatus('pending_confirmation');
        // Flush erfolgt in executeToolCall!
        $statusMessage = $recipient !== 'Unbekannt' 
            ? sprintf('📧 E-Mail vorbereitet an %s - Warte auf Freigabe', $recipient)
            : '⚠️ E-Mail vorbereitet - Bitte Empfänger manuell eingeben';

        $this->statusService->addStatus(
            $step->getWorkflow()->getSessionId(),
            sprintf('📧 E-Mail vorbereitet an %s - Warte auf Freigabe', $recipient)
        );

        return [
            'tool' => 'send_email',
            'status' => 'prepared',
            'email_details' => $emailDetails
        ];
    }



    private function executeContactFinder(WorkflowStep $step, array $parameters): array
    {
        $companyName = $parameters['company_name'] ?? null;
        
        if (!$companyName) {
            throw new \RuntimeException('Kein Firmenname angegeben');
        }

        $this->logger->info('Searching company contacts', ['company' => $companyName]);

        try {
            $result = ($this->contactFinderTool)($companyName);
            
            if ($this->isContactFinderSuccessful($result)) {
                return [
                    'tool' => 'company_career_contact_finder',
                    'result' => $result
                ];
            }
            
            throw new \RuntimeException('Keine Kontakte gefunden');

        } catch (\Exception $e) {
            $this->logger->error('Contact finder failed', [
                'company' => $companyName,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    private function executeGenericTool(string $toolName, array $parameters): array
    {
        $prompt = sprintf(
            'Verwende Tool "%s" mit Parametern: %s',
            $toolName,
            json_encode($parameters, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        return [
            'tool' => $toolName,
            'result' => $result->getContent()
        ];
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    private function pauseForConfirmation(Workflow $workflow, WorkflowStep $step): void
    {
        $workflow->setStatus('pending_confirmation');
        $step->setStatus('pending_confirmation');
        $workflow->setCurrentStep($step->getStepNumber());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            sprintf('⏸️ Warte auf Bestätigung: %s', $step->getDescription())
        );
    }

    private function handleStepFailure(Workflow $workflow, WorkflowStep $step, \Exception $e): void
    {
        $this->logger->error('Step failed', [
            'step' => $step->getStepNumber(),
            'error' => $e->getMessage()
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

    private function isRetriable(\Exception $e): bool
    {
        $msg = strtolower($e->getMessage());
        
        $retriablePatterns = [
            'timeout', 'rate limit', '429', '503', '502', '500',
            'temporarily unavailable', 'connection'
        ];

        foreach ($retriablePatterns as $pattern) {
            if (str_contains($msg, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function extractStructuredJson(string $content, array $requiredFields): array
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $content, $matches)) {
            $json = $matches[0];
        } else {
            throw new \RuntimeException('Kein JSON gefunden in Agent-Response');
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON Parse Error: ' . json_last_error_msg());
        }

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logger->warning("Missing field: {$field}");
            }
        }

        return $data;
    }

    private function collectJobsFromContext(array $context): array
    {
        $jobs = [];

        $this->logger->info('🔍 Collecting jobs from context', [
            'context_steps' => array_keys($context)
        ]);

        foreach ($context as $stepKey => $stepData) {
            if (!isset($stepData['result'])) {
                continue;
            }
            
            $result = $stepData['result'];
            
            if (isset($result['data']) && is_array($result['data'])) {
                if (isset($result['data']['data']['stellenangebote']) && is_array($result['data']['data']['stellenangebote'])) {
                    foreach ($result['data']['data']['stellenangebote'] as $job) {
                        $jobs[] = $this->normalizeJob($job);
                    }
                }
            }
            elseif (isset($result['stellenangebote']) && is_array($result['stellenangebote'])) {
                foreach ($result['stellenangebote'] as $job) {
                    $jobs[] = $this->normalizeJob($job);
                }
            }
            elseif (isset($result['jobs']) && is_array($result['jobs'])) {
                foreach ($result['jobs'] as $job) {
                    $jobs[] = $this->normalizeJob($job);
                }
            }
            elseif (isset($result['job_title']) || isset($result['title']) || isset($result['titel'])) {
                $jobs[] = $this->normalizeJob($result);
            }
        }

        // Dedupliziere
        $unique = [];
        foreach ($jobs as $job) {
            $url = $job['url'] ?? '';
            if ($url && !isset($unique[$url])) {
                $unique[$url] = $job;
            } elseif (!$url) {
                $key = md5(json_encode($job));
                $unique[$key] = $job;
            }
        }

        return array_values($unique);
    }

    private function normalizeJob(array $job): array
    {
        return [
            'title' => $job['titel'] ?? $job['title'] ?? $job['job_title'] ?? 'Unbekannt',
            'company' => $job['arbeitgeber'] ?? $job['company'] ?? $job['company_name'] ?? 'Unbekannt',
            'url' => $job['externeUrl'] ?? $job['url'] ?? $job['job_url'] ?? '',
            'location' => $job['arbeitsort']['ort'] ?? $job['location'] ?? $job['ort'] ?? '',
            'description' => $job['description'] ?? $job['snippet'] ?? '',
            'date' => $job['aktuelleVeroeffentlichungsdatum'] ?? $job['date'] ?? null,
            'refnr' => $job['refnr'] ?? null,
            'beruf' => $job['beruf'] ?? null
        ];
    }

    private function parseAttachments(mixed $attachments): array
    {
        if (is_string($attachments)) {
            $decoded = json_decode($attachments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $attachments = $decoded;
            } else {
                $attachments = array_filter(explode(',', $attachments));
            }
        }

        if (!is_array($attachments)) {
            return [];
        }

        $ids = [];
        foreach ($attachments as $item) {
            if (is_array($item)) {
                $ids[] = $item['value'] ?? $item['id'] ?? null;
            } else {
                $ids[] = $item;
            }
        }

        return array_filter($ids);
    }

    private function loadAttachmentDetails(array $ids, User $user): array
    {
        $details = [];

        foreach ($ids as $id) {
            $document = $this->documentRepo->find($id);
            
            if ($document && $document->getUser() === $user) {
                $details[] = [
                    'id' => $document->getId(),
                    'filename' => $document->getOriginalFilename(),
                    'size' => $document->getFileSize(),
                    'mime_type' => $document->getMimeType()
                ];
            }
        }

        return $details;
    }

    private function isContactFinderSuccessful(array $result): bool
    {
        if (isset($result['success']) && $result['success'] === true) {
            return true;
        }

        return !empty($result['application_email']) || !empty($result['general_email']);
    }
}