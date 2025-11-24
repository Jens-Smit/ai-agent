<?php
// src/Service/Workflow/WorkflowExecutor.php

declare(strict_types=1);

namespace App\Service\Workflow;

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

/**
 * Workflow-Executor: Führt Workflows mit Retry-Logik und strukturierter Daten-Extraktion aus
 */
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
        private LoggerInterface $logger
    ) {}

    /**
     * Führt einen Workflow aus
     */
    public function executeWorkflow(Workflow $workflow): void
    {
        $workflow->setStatus('running');
        $this->em->flush();

        $context = []; // Shared context zwischen Steps

        foreach ($workflow->getSteps() as $step) {
            if ($step->getStatus() === 'completed') {
                continue;
            }

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Führe Step %d aus: %s', $step->getStepNumber(), $step->getDescription())
            );

            try {
                $result = $this->executeStep($step, $context, $workflow->getSessionId());

                // Speichere Ergebnis im Context
                $context['step_' . $step->getStepNumber()] = ['result' => $result];

                $step->setResult($result);
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());

                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen', $step->getStepNumber())
                );

                // Bei Bestätigungs-Requirement: Pausiere Workflow
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

        // Workflow erfolgreich abgeschlossen
        $workflow->setStatus('completed');
        $workflow->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '🎉 Workflow erfolgreich abgeschlossen!'
        );
    }

    /**
     * Bestätigt Step und führt Workflow fort
     */
    public function confirmAndContinue(Workflow $workflow, WorkflowStep $step): void
    {
        // Für E-Mail: Führe tatsächliches Versenden aus
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

        // Führe verbleibende Steps aus
        $this->executeWorkflow($workflow);
    }

    /**
     * Führt einzelnen Step aus mit Retry-Logik
     */
    private function executeStep(WorkflowStep $step, array $context, string $sessionId): array
    {
        $maxRetries = 3;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $result = match ($step->getStepType()) {
                    'tool_call' => $this->executeToolCall($step, $context, $sessionId),
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

                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('⚠️ Retry %d/%d: %s', $attempt, $maxRetries, substr($e->getMessage(), 0, 80))
                );

                sleep(min(pow(2, $attempt - 1) * 5, 30)); // Exponential backoff
            }
        }

        throw $lastException ?? new \RuntimeException('Step failed after retries');
    }

    /**
     * Führt Tool-Call aus
     */
    private function executeToolCall(WorkflowStep $step, array $context, string $sessionId): array
    {
        $toolName = $step->getToolName();
        $parameters = $this->resolveContextPlaceholders($step->getToolParameters(), $context);

        $this->logger->info('Executing tool call', [
            'tool' => $toolName,
            'parameters' => $parameters
        ]);

        // Spezialbehandlung für E-Mail
        if ($toolName === 'send_email' || $toolName === 'SendMailTool') {
            return $this->prepareSendMailDetails($step, $parameters, $sessionId, $context);
        }

        // Standard Tool-Call
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
     * Führt Analysis aus mit strukturierter JSON-Extraktion
     */
    private function executeAnalysis(WorkflowStep $step, array $context, string $sessionId): array
    {
        $expectedFormat = $step->getExpectedOutputFormat();

        if ($expectedFormat && isset($expectedFormat['fields'])) {
            // Strukturierte Analyse mit vorgegebenem Format
            return $this->executeStructuredAnalysis($step, $context, $sessionId, $expectedFormat['fields']);
        }

        // Fallback: Unstrukturierte Analyse
        $prompt = sprintf(
            'Analysiere folgende Daten und %s: %s',
            $step->getDescription(),
            json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        return ['analysis' => $result->getContent()];
    }

    /**
     * Führt strukturierte Analyse mit erzwungenem JSON-Output aus
     */
    private function executeStructuredAnalysis(
        WorkflowStep $step,
        array $context,
        string $sessionId,
        array $requiredFields
    ): array {
        $fieldsList = implode(', ', array_keys($requiredFields));

        $prompt = sprintf(
            'Analysiere die folgenden Daten und %s.

WICHTIG: Antworte NUR mit einem gültigen JSON-Objekt, das EXAKT diese Felder enthält: %s

Verwende dieses Format:
```json
{
%s
}
```

Daten zur Analyse:
%s

Antworte AUSSCHLIESSLICH mit dem JSON-Objekt, ohne zusätzlichen Text davor oder danach.',
            $step->getDescription(),
            $fieldsList,
            implode(",\n", array_map(fn($k) => "  \"$k\": \"...\"", array_keys($requiredFields))),
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        // Extrahiere JSON aus Antwort
        $structuredData = $this->extractStructuredJson($result->getContent(), array_keys($requiredFields));

        $this->logger->info('Structured analysis completed', [
            'step' => $step->getStepNumber(),
            'extracted_fields' => array_keys($structuredData)
        ]);

        return $structuredData;
    }

    /**
     * Extrahiert strukturiertes JSON aus Agent-Antwort
     */
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
                'error' => json_last_error_msg()
            ]);
            return $this->extractKeyValuePairs($content, $requiredFields);
        }

        // Validiere dass alle erforderlichen Felder vorhanden sind
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logger->warning('Missing required field, attempting extraction', [
                    'field' => $field
                ]);
                $data[$field] = $this->extractFieldFromText($content, $field);
            }
        }

        return $data;
    }

    /**
     * Extrahiert Key-Value-Paare aus Text als Fallback
     */
    private function extractKeyValuePairs(string $content, array $requiredFields): array
    {
        $result = [];

        foreach ($requiredFields as $field) {
            $value = $this->extractFieldFromText($content, $field);
            $result[$field] = $value ?? '';
        }

        return $result;
    }

    /**
     * Extrahiert einzelnes Feld aus Text mittels Pattern-Matching
     */
    private function extractFieldFromText(string $content, string $fieldName): ?string
    {
        // Pattern 1: "field_name": "value" oder "field_name": value
        if (preg_match('/"' . preg_quote($fieldName) . '"\s*:\s*"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }
        if (preg_match('/"' . preg_quote($fieldName) . '"\s*:\s*([^,}\s]+)/', $content, $matches)) {
            return trim($matches[1]);
        }

        // Pattern 2: field_name: value oder field_name = value
        if (preg_match('/' . preg_quote($fieldName) . '\s*[:=]\s*"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }
        if (preg_match('/' . preg_quote($fieldName) . '\s*[:=]\s*([^\n,]+)/', $content, $matches)) {
            return trim($matches[1]);
        }

        // Pattern 3: Natürlichsprachlich (z.B. "Der Firmenname ist X")
        $fieldLabel = str_replace('_', ' ', $fieldName);
        if (preg_match('/(?:der|die|das)?\s*' . preg_quote($fieldLabel) . '\s+(?:ist|lautet)\s+"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(?:der|die|das)?\s*' . preg_quote($fieldLabel) . '\s+(?:ist|lautet)\s+([^\n.]+)/', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Führt Decision aus
     */
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

    /**
     * Führt Notification aus
     */
    private function executeNotification(WorkflowStep $step, array $context, string $sessionId): array
    {
        $message = $this->resolveContextPlaceholders($step->getDescription(), $context);

        $this->statusService->addStatus($sessionId, '📧 ' . $message);

        return [
            'notification_sent' => true,
            'message' => $message
        ];
    }

    /**
     * Bereitet E-Mail vor
     */
    private function prepareSendMailDetails(
        WorkflowStep $step,
        array $parameters,
        string $sessionId,
        array $context
    ): array {
        $userId = $GLOBALS['current_user_id'] ?? null;
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

    /**
     * Versendet E-Mail nach Bestätigung
     */
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

    /**
     * Löst Context-Platzhalter auf
     */
    private function resolveContextPlaceholders(mixed $data, array $context): mixed
    {
        if (is_string($data)) {
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function ($matches) use ($context) {
                    $path = $matches[1];
                    $parts = preg_split('/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);

                    $value = $context;
                    foreach ($parts as $key) {
                        if (is_array($value)) {
                            $value = $value[$key] ?? null;
                        } else {
                            return $matches[0]; // Behalte Platzhalter wenn nicht auflösbar
                        }

                        if ($value === null) {
                            $this->logger->debug('Placeholder not resolved', [
                                'placeholder' => $matches[0],
                                'path' => $path
                            ]);
                            return $matches[0];
                        }
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

    /**
     * Agent-Call mit Fallback auf Flash Lite
     */
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

            if ($this->agentFailureCount >= 3 && !$this->useFlashLite) {
                $this->useFlashLite = true;
                $this->statusService->addStatus($sessionId, '⚠️ Wechsle zu Flash Lite');
                return $this->callAgentWithFallback($messages, $sessionId);
            }

            throw $e;
        }
    }

    /**
     * Prüft ob Fehler transient ist
     */
    private function isTransientError(string $error): bool
    {
        $transient = ['response does not contain', 'rate limit', 'timeout', '503', '429', '500'];
        return str_contains(strtolower($error), implode('|', $transient));
    }

    /**
     * Behandelt Step-Fehler
     */
    private function handleStepFailure(Workflow $workflow, WorkflowStep $step, \Exception $e): void
    {
        $this->logger->error('Step failed', [
            'workflow_id' => $workflow->getId(),
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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < 3; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}