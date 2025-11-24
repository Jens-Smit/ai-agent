<?php
// src/Service/Workflow/WorkflowExecutor.php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\UserDocumentRepository;
use App\Service\AgentStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Tool\CompanyCareerContactFinderTool;

final class WorkflowExecutor
{
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

    public function executeWorkflow(Workflow $workflow, ?User $user = null): void
    {
        $this->throttle();
        $workflow->setStatus('running');
        $this->em->flush();

        $context = [];

        foreach ($workflow->getSteps() as $step) {
            $this->throttle();

            if ($step->getStatus() === 'completed') {
                continue;
            }

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Führe Step %d aus: %s', $step->getStepNumber(), $step->getDescription())
            );

            try {
                $result = $this->executeStep($step, $context, $workflow->getSessionId(), $user);

                // 🔧 FIX: Speichere Ergebnis strukturiert im Context
                $context['step_' . $step->getStepNumber()] = ['result' => $result];

                $step->setResult($result);
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());

                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen', $step->getStepNumber())
                );

                if ($step->requiresConfirmation()) {
                    $workflow->setStatus('waiting_confirmation');
                    $workflow->setCurrentStep($step->getStepNumber());
                    $this->em->flush();

                    $this->statusService->addStatus(
                        $workflow->getSessionId(),
                        sprintf('⏸️ Warte auf Bestätigung für: %s', $step->getDescription())
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

    private function throttle(): void
    {
        usleep(500000); // 500ms Pause (erhöht von 200ms)
    }

    public function confirmAndContinue(Workflow $workflow, WorkflowStep $step): void
    {
        if ($step->getToolName() === 'send_email' && $step->getEmailDetails()) {
            try {
                $result = $this->executeSendEmail($step, $workflow->getSessionId());
                $step->setResult($result);
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());
            } catch (\Exception $e) {
                $this->handleStepFailure($workflow, $step, $e);
                return;
            }
        } else {
            $step->setStatus('completed');
            $step->setCompletedAt(new \DateTimeImmutable());
        }

        $workflow->setStatus('running');
        $workflow->setCurrentStep(null);
        $this->em->flush();

        $this->executeWorkflow($workflow);
    }

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

    
    private function executeToolCall(WorkflowStep $step, array $context, string $sessionId, ?User $user): array
    {
        $toolName = $step->getToolName();
        $parameters = $this->resolveContextPlaceholders($step->getToolParameters(), $context);

        $this->logger->info('Executing tool call', [
            'tool' => $toolName,
            'parameters' => $parameters,
            'context_keys' => array_keys($context)
        ]);

        // 🔧 FIX: Prüfe ob Platzhalter aufgelöst wurden
        $unresolvedPlaceholders = $this->findUnresolvedPlaceholders($parameters);
        if (!empty($unresolvedPlaceholders)) {
            $this->logger->error('Unresolved placeholders detected', [
                'placeholders' => $unresolvedPlaceholders,
                'available_context' => array_keys($context)
            ]);

            throw new \RuntimeException(
                sprintf('Unresolved placeholders: %s. Context verfügbar: %s',
                    implode(', ', $unresolvedPlaceholders),
                    implode(', ', array_keys($context))
                )
            );
        }
        
        // NEUE LOGIK: Spezielles Tool-Handling für company_career_contact_finder
        if ($toolName === 'company_career_contact_finder') {
            return $this->executeCompanyContactFinderWithFallback($step, $parameters, $context, $sessionId);
        }

        if ($toolName === 'send_email' || $toolName === 'SendMailTool') {
            return $this->prepareSendMailDetails($step, $parameters, $sessionId, $context, $user);
        }

        $prompt = sprintf(
            'Verwende das Tool "%s" mit folgenden Parametern: %s',
            $toolName,
            json_encode($parameters, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        return [
            'tool' => $toolName,
            'result' => $result->getContent()
        ];
    }
    
    /**
     * Führt das CompanyCareerContactFinderTool aus und versucht bei Misserfolg,
     * die nächste Job-URL aus Schritt 1 zu verwenden.
     * @throws \RuntimeException Wenn alle Fallbacks fehlschlagen oder keine Daten verfügbar sind.
     */
   
   
     private function executeCompanyContactFinderWithFallback(
        WorkflowStep $step,
        array $parameters,
        array $context,
        string $sessionId
    ): array {
        $jobResults = $context['step_1']['result']['job_search_results'] ?? [];
        $initialCompanyName = $parameters['company_name'] ?? null;

        $urlsToTry = [];

        if ($initialCompanyName) {
            $urlsToTry[] = [
                'type' => 'company_name',
                'value' => $initialCompanyName,
                'description' => sprintf('Initialer Firmenname: %s', $initialCompanyName)
            ];
        }

        foreach ($jobResults as $index => $result) {
            if (!empty($result['url'])) {
                $urlsToTry[] = [
                    'type' => 'job_url',
                    'value' => $result['url'],
                    'description' => sprintf('Job-URL #%d: %s', $index + 1, $result['url']),
                    'company' => $result['company'] ?? null
                ];
            }
        }

        $foundResult = null;
        $attempt = 0;

        foreach ($urlsToTry as $item) {
            $attempt++;
            $this->statusService->addStatus(
                $sessionId,
                sprintf('🔄 Starte Kontakt-Suche (Versuch %d): %s', $attempt, $item['description'])
            );

            $searchParam = $item['value'];
            
            if ($item['type'] === 'job_url') {
                $searchParam = $item['company'] ?? $initialCompanyName;
                
                if (empty($searchParam)) {
                    $this->logger->warning(sprintf('Kein Firmenname für Fallback-Job-URL gefunden. Überspringe Versuch %d.', $attempt));
                    continue;
                }
                
                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('ℹ️ Suche nach Kontakt für Firma: %s', $searchParam)
                );
            }

            try {
                // ✅ DIREKTER Tool-Aufruf via Dependency Injection
                $this->logger->info('Rufe CompanyCareerContactFinderTool direkt auf', [
                    'company_name' => $searchParam,
                    'attempt' => $attempt
                ]);
                
                // Rufe die __invoke() Methode des Tools direkt auf
                $toolResult = ($this->contactFinderTool)($searchParam);
                
                $this->logger->info('Tool-Aufruf abgeschlossen', [
                    'attempt' => $attempt,
                    'success' => $toolResult['success'] ?? false,
                    'has_general_email' => !empty($toolResult['general_email']),
                    'has_application_email' => !empty($toolResult['application_email']),
                    'contact_person' => $toolResult['contact_person'] ?? null
                ]);
                
                if ($this->isContactFinderSuccessful($toolResult)) {
                    $foundResult = [
                        'tool' => $step->getToolName(),
                        'result' => $toolResult
                    ];
                    
                    $emailInfo = [];
                    if (!empty($toolResult['application_email'])) {
                        $emailInfo[] = 'Bewerbungs-E-Mail: ' . $toolResult['application_email'];
                    }
                    if (!empty($toolResult['general_email'])) {
                        $emailInfo[] = 'Allgemeine E-Mail: ' . $toolResult['general_email'];
                    }
                    
                    $this->statusService->addStatus(
                        $sessionId, 
                        '✅ Kontaktdaten gefunden: ' . implode(', ', $emailInfo)
                    );
                    break;
                }
                
                $this->statusService->addStatus($sessionId, '⚠️ Keine relevanten Kontaktdaten gefunden. Versuche nächsten Fallback.');

            } catch (\Throwable $e) {
                $this->logger->warning('Tool-Ausführung fehlgeschlagen', [
                    'attempt' => $attempt,
                    'company_name' => $searchParam,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                continue;
            }
        }

        if ($foundResult) {
            return $foundResult;
        }

        throw new \RuntimeException(sprintf(
            'Kontaktdaten konnten nach %d Versuchen nicht gefunden werden. Der Workflow kann nicht fortgesetzt werden.',
            $attempt
        ));
    }

    /**
     * Prüft, ob das Ergebnis des ContactFinders erfolgreich ist.
     */
    private function isContactFinderSuccessful(array $result): bool
    {
        // Prüfe success Flag vom Tool
        if (isset($result['success']) && $result['success'] === true) {
            return true;
        }

        // Fallback: Mindestens eine relevante E-Mail gefunden
        return !empty($result['application_email']) || !empty($result['general_email']);
    }
    

    /**
     * Ruft das CompanyCareerContactFinderTool direkt auf (ohne Agent-Wrapper)
     */
    private function invokeContactFinderDirectly(string $companyName): array
    {
        // Hole das Tool direkt aus der Toolbox
        $toolbox = $this->agent->getToolbox();
        
        if (!$toolbox) {
            throw new \RuntimeException('Agent hat keine Toolbox konfiguriert');
        }
        
        // Suche das CompanyCareerContactFinderTool
        $contactFinderTool = null;
        foreach ($toolbox->all() as $tool) {
            if ($tool->getName() === 'company_career_contact_finder') {
                $contactFinderTool = $tool;
                break;
            }
        }
        
        if (!$contactFinderTool) {
            throw new \RuntimeException('CompanyCareerContactFinderTool nicht in Toolbox gefunden');
        }
        
        // Rufe das Tool direkt auf
        $this->logger->info('Rufe Tool direkt auf', [
            'tool' => 'company_career_contact_finder',
            'company_name' => $companyName
        ]);
        
        $result = $contactFinderTool($companyName);
        
        // Das Tool gibt bereits ein Array zurück
        if (!is_array($result)) {
            throw new \RuntimeException('Tool gab kein Array zurück: ' . gettype($result));
        }
        
        return $result;
    }

    
    
    

    // 🔧 FIX: Neue Methode zum Finden unaufgelöster Platzhalter
    private function findUnresolvedPlaceholders(mixed $data): array
    {
        $unresolved = [];

        if (is_string($data)) {
            if (preg_match_all('/\{\{([^}]+)\}\}/', $data, $matches)) {
                $unresolved = array_merge($unresolved, $matches[0]);
            }
        } elseif (is_array($data)) {
            foreach ($data as $value) {
                $unresolved = array_merge($unresolved, $this->findUnresolvedPlaceholders($value));
            }
        }

        return array_unique($unresolved);
    }

    private function executeAnalysis(WorkflowStep $step, array $context, string $sessionId): array
    {
        $expectedFormat = $step->getExpectedOutputFormat();

        if ($expectedFormat && isset($expectedFormat['fields'])) {
            return $this->executeStructuredAnalysis($step, $context, $sessionId, $expectedFormat['fields']);
        }

        // 🔧 FIX: Auch unstrukturierte Analysis sollte versuchen, Felder zu extrahieren
        $prompt = sprintf(
            'Analysiere folgende Daten und %s: %s',
            $step->getDescription(),
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        return ['analysis' => $result->getContent()];
    }

    private function executeStructuredAnalysis(
        WorkflowStep $step,
        array $context,
        string $sessionId,
        array $requiredFields
    ): array {
        $fieldsList = implode(', ', array_keys($requiredFields));

        // 🔧 FIX: Verbesserte Prompt mit klareren Anweisungen
        $prompt = sprintf(
            'Analysiere die folgenden Daten und %s.

KRITISCH WICHTIG: 
1. Antworte NUR mit einem gültigen JSON-Objekt
2. Das JSON MUSS EXAKT diese Felder enthalten: %s
3. Kein Text vor oder nach dem JSON
4. Keine Markdown-Formatierung außer ```json Block

Format:
```json
{
%s
}
```

Daten zur Analyse:
%s',
            $step->getDescription(),
            $fieldsList,
            implode(",\n", array_map(fn($k) => "  \"$k\": \"<wert für $k>\"", array_keys($requiredFields))),
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        $content = $result->getContent();
        
        // 🔧 FIX: Protokolliere die Antwort für Debugging
        $this->logger->info('Structured analysis response', [
            'step' => $step->getStepNumber(),
            'content_preview' => substr($content, 0, 500)
        ]);

        $structuredData = $this->extractStructuredJson($content, array_keys($requiredFields));

        $this->logger->info('Structured analysis completed', [
            'step' => $step->getStepNumber(),
            'extracted_fields' => array_keys($structuredData),
            'field_values' => $structuredData
        ]);

        return $structuredData;
    }

    private function extractStructuredJson(string $content, array $requiredFields): array
    {
        // Strategie 1: JSON in Code-Block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        }
        // Strategie 2: Erstes vollständiges JSON-Objekt
        elseif (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $content, $matches)) {
            $json = $matches[0];
        } else {
            $this->logger->warning('No JSON found, attempting key-value extraction', [
                'content_preview' => substr($content, 0, 200)
            ]);
            return $this->extractKeyValuePairs($content, $requiredFields);
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('JSON decode failed, falling back to key-value extraction', [
                'error' => json_last_error_msg(),
                'json_preview' => substr($json, 0, 200)
            ]);
            return $this->extractKeyValuePairs($content, $requiredFields);
        }

        // Validiere dass alle erforderlichen Felder vorhanden sind
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $this->logger->warning('Missing or empty required field, attempting extraction', [
                    'field' => $field
                ]);
                $extracted = $this->extractFieldFromText($content, $field);
                if ($extracted) {
                    $data[$field] = $extracted;
                }
            }
        }

        return $data;
    }

    private function extractKeyValuePairs(string $content, array $requiredFields): array
    {
        $result = [];

        foreach ($requiredFields as $field) {
            $value = $this->extractFieldFromText($content, $field);
            $result[$field] = $value ?? '';
        }

        return $result;
    }

    private function extractFieldFromText(string $content, string $fieldName): ?string
    {
        // Pattern 1: "field_name": "value"
        if (preg_match('/"' . preg_quote($fieldName) . '"\s*:\s*"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }

        // Pattern 2: **Firmenname:** plusYou GmbH
        if (preg_match('/\*\*' . preg_quote(str_replace('_', ' ', $fieldName)) . '[:\*]*\s*([^\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        // Pattern 3: Firmenname: plusYou GmbH
        if (preg_match('/' . preg_quote(str_replace('_', ' ', $fieldName)) . '\s*:\s*([^\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        // Pattern 4: - Arbeitgeber: plusYou GmbH
        if (preg_match('/-\s*' . preg_quote(str_replace('_', ' ', $fieldName)) . '\s*:\s*([^\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function executeDecision(WorkflowStep $step, array $context, string $sessionId): array
    {
        $expectedFormat = $step->getExpectedOutputFormat();

        if ($expectedFormat && isset($expectedFormat['fields'])) {
            return $this->executeStructuredAnalysis($step, $context, $sessionId, $expectedFormat['fields']);
        }

        $prompt = sprintf(
            'Treffe folgende Entscheidung: %s. Basierend auf: %s',
            $step->getDescription(),
            json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        return ['decision' => $result->getContent()];
    }

    private function executeNotification(WorkflowStep $step, array $context, string $sessionId): array
    {
        $message = $this->resolveContextPlaceholders($step->getDescription(), $context);

        $this->statusService->addStatus($sessionId, '📧 ' . $message);

        return [
            'notification_sent' => true,
            'message' => $message
        ];
    }

    private function prepareSendMailDetails(
        WorkflowStep $step,
        array $parameters,
        string $sessionId,
        array $context,
        ?User $user
    ): array {
        $userId = $user?->getId();
        if (!$userId) {
            throw new \RuntimeException('User context not available');
        }

        $recipient = $parameters['to'] ?? 'Unbekannt';
        $subject = $parameters['subject'] ?? 'Kein Betreff';
        $body = $parameters['body'] ?? '';
        $attachmentIds = $parameters['attachments'] ?? [];

        if (!is_array($attachmentIds)) {
            $attachmentIds = [$attachmentIds];
        }

        $attachmentDetails = [];
        foreach ($attachmentIds as $docId) {
            if (empty($docId)) continue;

            $document = $this->documentRepo->find($docId);
            if ($document && $document->getUser()->getId() === $userId) {
                $attachmentDetails[] = [
                    'id' => $document->getId(),
                    'filename' => $document->getOriginalFilename(),
                    'size' => $document->getFileSize(),
                    'size_human' => $this->formatBytes($document->getFileSize()),
                    'mime_type' => $document->getMimeType()
                ];
            }
        }

        $emailDetails = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'body_preview' => mb_substr(strip_tags($body), 0, 200) . '...',
            'attachments' => $attachmentDetails,
            'attachment_count' => count($attachmentDetails),
            'ready_to_send' => true,
            '_original_params' => $parameters
        ];

        $step->setEmailDetails($emailDetails);
        $this->em->flush();

        $this->statusService->addStatus(
            $sessionId,
            sprintf('📧 E-Mail vorbereitet an %s - Betreff: "%s"', $recipient, mb_substr($subject, 0, 50))
        );

        return [
            'tool' => 'send_email',
            'status' => 'prepared',
            'email_details' => $emailDetails
        ];
    }

    private function executeSendEmail(WorkflowStep $step, string $sessionId): array
    {
        $emailDetails = $step->getEmailDetails();

        $prompt = sprintf(
            'Sende E-Mail: %s',
            json_encode([
                'to' => $emailDetails['recipient'],
                'subject' => $emailDetails['subject'],
                'body' => $emailDetails['body'],
                'attachments' => array_column($emailDetails['attachments'], 'id')
            ])
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        $this->statusService->addStatus(
            $sessionId,
            sprintf('✅ E-Mail versendet an %s', $emailDetails['recipient'])
        );

        return [
            'tool' => 'send_email',
            'status' => 'sent',
            'recipient' => $emailDetails['recipient'],
            'sent_at' => (new \DateTimeImmutable())->format('c')
        ];
    }

    // 🔧 FIX: Verbesserte Platzhalter-Auflösung mit Debugging
    private function resolveContextPlaceholders(mixed $data, array $context): mixed
    {
        if (is_string($data)) {
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function ($matches) use ($context) {
                    $path = trim($matches[1]);
                    $parts = preg_split('/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);

                    $this->logger->debug('Resolving placeholder', [
                        'placeholder' => $matches[0],
                        'path' => $path,
                        'parts' => $parts,
                        'context_keys' => array_keys($context)
                    ]);

                    $value = $context;
                    foreach ($parts as $key) {
                        if (is_array($value) && isset($value[$key])) {
                            $value = $value[$key];
                        } else {
                            $this->logger->warning('Placeholder path not found', [
                                'placeholder' => $matches[0],
                                'path' => $path,
                                'missing_key' => $key,
                                'current_value_type' => gettype($value),
                                'available_keys' => is_array($value) ? array_keys($value) : 'not_an_array'
                            ]);
                            return $matches[0]; // Behalte Platzhalter
                        }
                    }

                    if ($value === null) {
                        return $matches[0];
                    }

                    return is_scalar($value) ? (string)$value : json_encode($value);
                },
                $data
            );
        }

        if (is_array($data)) {
            return array_map(fn($item) => $this->resolveContextPlaceholders($item, $context), $data);
        }

        return $data;
    }

    private function callAgentWithFallback(MessageBag $messages, string $sessionId): mixed
    {
        try {
            if ($this->useFlashLite) {
                if (!$this->flashLiteAgent) {
                    $this->flashLiteAgent = new \Symfony\AI\Agent\Agent(
                        $this->platform,
                        'gemini-2.0-flash-lite'
                    );

                    if ($toolbox = $this->agent->getToolbox()) {
                        $this->flashLiteAgent->setToolbox($toolbox);
                    }
                }

                $this->statusService->addStatus($sessionId, '🔄 Nutze Flash Lite (Fallback)');
                return $this->flashLiteAgent->call($messages);
            }

            $result = $this->agent->call($messages);
            $this->agentFailureCount = 0;
            return $result;

        } catch (\Throwable $e) {
            $this->agentFailureCount++;

            $this->logger->warning('Agent call failed', [
                'failure_count' => $this->agentFailureCount,
                'error' => $e->getMessage()
            ]);

            if ($this->agentFailureCount >= 3 && !$this->useFlashLite) {
                $this->useFlashLite = true;
                $this->statusService->addStatus($sessionId, '⚠️ Wechsle zu Flash Lite');
                sleep(2); // Kurze Pause vor Fallback
                return $this->callAgentWithFallback($messages, $sessionId);
            }

            throw $e;
        }
    }

    private function isTransientError(string $error): bool
    {
        $lowerError = strtolower($error);
        $transientPatterns = [
            'response does not contain',
            'rate limit',
            'timeout',
            '503',
            '429',
            '500',
            'temporarily unavailable'
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($lowerError, $pattern)) {
                return true;
            }
        }

        return false;
    }

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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < 3; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}