<?php
// src/Service/Workflow/Executor/ToolExecutionTrait.php

declare(strict_types=1);

namespace App\Service\Workflow\Executor;

use App\Entity\User;
use App\Entity\WorkflowStep;
use App\Tool\CompanyCareerContactFinderTool;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

trait ToolExecutionTrait
{
    /**
     * Führt einen Tool-Call aus
     */
    private function executeToolCall(WorkflowStep $step, array $context, string $sessionId, ?User $user): array
    {
        $toolName = $step->getToolName();
        $parameters = $this->resolveContextPlaceholders($step->getToolParameters(), $context);

        $this->logger->info('Executing tool call', [
            'tool' => $toolName,
            'parameters' => $parameters,
            'context_keys' => array_keys($context),
            'has_user' => $user !== null
        ]);

        // Prüfe unaufgelöste Platzhalter
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
        
        // Spezielles Handling für verschiedene Tools
        if ($toolName === 'company_career_contact_finder') {
            return $this->executeCompanyContactFinderWithFallback($step, $parameters, $context, $sessionId);
        }

        if ($toolName === 'send_email' || $toolName === 'SendMailTool') {
            return $this->prepareSendMailDetails($step, $parameters, $sessionId, $context, $user);
        }

        // Standard Tool-Aufruf via Agent
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
     * Führt das CompanyCareerContactFinderTool mit Fallback-Logik aus
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
                $this->logger->info('Rufe CompanyCareerContactFinderTool direkt auf', [
                    'company_name' => $searchParam,
                    'attempt' => $attempt
                ]);
                
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
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        if ($foundResult) {
            return $foundResult;
        }

        throw new \RuntimeException(sprintf(
            'Kontaktdaten konnten nach %d Versuchen nicht gefunden werden.',
            $attempt
        ));
    }

    /**
     * Prüft, ob das ContactFinder-Ergebnis erfolgreich ist
     */
    private function isContactFinderSuccessful(array $result): bool
    {
        if (isset($result['success']) && $result['success'] === true) {
            return true;
        }

        return !empty($result['application_email']) || !empty($result['general_email']);
    }

    /**
     * Bereitet E-Mail-Details vor und pausiert Workflow für User-Bestätigung
     */
    private function prepareSendMailDetails(
        WorkflowStep $step,
        array $parameters,
        string $sessionId,
        array $context,
        ?User $user
    ): array {
        if (!$user) {
            throw new \RuntimeException('User context not available - cannot prepare email');
        }

        $recipient = $parameters['to'] ?? 'Unbekannt';
        $subject = $parameters['subject'] ?? 'Kein Betreff';
        $body = $parameters['body'] ?? '';
        $attachmentIds = [];

        // ✅ VERBESSERT: Parse attachments String zu Array
        if (isset($parameters['attachments'])) {
            $attachmentsParam = $parameters['attachments'];
            
            // Wenn es ein JSON-String ist, parse ihn
            if (is_string($attachmentsParam)) {
                $decoded = json_decode($attachmentsParam, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $attachmentIds = $decoded;
                } else {
                    // Fallback: Split bei Komma
                    $attachmentIds = array_filter(array_map('trim', explode(',', $attachmentsParam)));
                }
            } elseif (is_array($attachmentsParam)) {
                $attachmentIds = $attachmentsParam;
            }
        }

        $this->logger->info('Preparing email with attachments', [
            'recipient' => $recipient,
            'attachment_ids' => $attachmentIds,
            'user_id' => $user->getId()
        ]);

        // Lade Anhänge mit vollständigen Details
        $attachmentDetails = [];
        foreach ($attachmentIds as $attachment) {
            if (empty($attachment)) continue;

            // Unterstütze verschiedene Attachment-Formate
            // Format 1: {"type": "document_id", "value": "3"}
            // Format 2: Direkte ID: "3"
            $docId = null;
            
            if (is_array($attachment)) {
                $docId = $attachment['value'] ?? $attachment['id'] ?? null;
            } else {
                $docId = $attachment;
            }
            
            if (empty($docId)) continue;

            $document = $this->documentRepo->find($docId);
            if ($document && $document->getUser()->getId() === $user->getId()) {
                $attachmentDetails[] = [
                    'id' => $document->getId(),
                    'filename' => $document->getOriginalFilename(),
                    'size' => $document->getFileSize(),
                    'size_human' => $this->formatBytes($document->getFileSize()),
                    'mime_type' => $document->getMimeType(),
                    'type' => $document->getDocumentType(),
                    'download_url' => sprintf('/api/documents/%d/download', $document->getId())
                ];
            } else {
                $this->logger->warning('Document not found or access denied', [
                    'doc_id' => $docId,
                    'user_id' => $user->getId()
                ]);
            }
        }

        // Erstelle strukturierte E-Mail-Details
        $emailDetails = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'body_preview' => mb_substr(strip_tags($body), 0, 200) . (mb_strlen($body) > 200 ? '...' : ''),
            'body_length' => mb_strlen($body),
            'attachments' => $attachmentDetails,
            'attachment_count' => count($attachmentDetails),
            'ready_to_send' => true,
            'prepared_at' => (new \DateTimeImmutable())->format('c'),
            '_original_params' => $parameters,
            '_user_id' => $user->getId() // ✅ NEU: User-ID speichern
        ];

        // Speichere E-Mail-Details im Step
        $step->setEmailDetails($emailDetails);
        $step->setStatus('pending_confirmation');
        $step->setRequiresConfirmation(true);
        
        $this->em->flush();

        $attachmentInfo = count($attachmentDetails) > 0 
            ? sprintf(' (%d Anhänge)', count($attachmentDetails))
            : '';

        $this->statusService->addStatus(
            $sessionId,
            sprintf('📧 E-Mail vorbereitet an %s - Betreff: "%s"%s (wartet auf Freigabe)', 
                $recipient, 
                mb_substr($subject, 0, 50),
                $attachmentInfo
            )
        );

        return [
            'tool' => 'send_email',
            'status' => 'prepared',
            'email_details' => $emailDetails
        ];
    }

    /**
     * Versendet eine vorbereitete E-Mail
     */
    private function executeSendEmail(WorkflowStep $step, string $sessionId, ?User $user): array
    {
        $emailDetails = $step->getEmailDetails();

        if (!$emailDetails || !isset($emailDetails['_original_params'])) {
            throw new \RuntimeException('Email details not found or incomplete');
        }

        // ✅ NEU: User aus Email-Details holen falls nicht übergeben
        if (!$user && isset($emailDetails['_user_id'])) {
            $user = $this->em->getRepository(User::class)->find($emailDetails['_user_id']);
        }

        if (!$user) {
            throw new \RuntimeException('User context required for sending email');
        }

        // Verwende Original-Parameter für Tool-Aufruf
        $params = $emailDetails['_original_params'];

        // Bereite Attachments für das SendMailTool vor
        $attachmentPaths = [];
        if (!empty($emailDetails['attachments'])) {
            foreach ($emailDetails['attachments'] as $attachment) {
                $document = $this->documentRepo->find($attachment['id']);
                if ($document && $document->getUser()->getId() === $user->getId()) {
                    $attachmentPaths[] = $document->getFullPath();
                }
            }
        }

        // Konstruiere Tool-Aufruf mit vollständigen Pfaden
        $toolParams = [
            'to' => $params['to'],
            'subject' => $params['subject'],
            'body' => $params['body'],
            'attachments' => $attachmentPaths // ✅ Verwende Pfade statt IDs
        ];

        $prompt = sprintf(
            'Sende E-Mail mit folgenden Details: %s',
            json_encode($toolParams, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        
        try {
            $result = $this->callAgentWithFallback($messages, $sessionId);

            $this->statusService->addStatus(
                $sessionId,
                sprintf('✅ E-Mail erfolgreich versendet an %s', $emailDetails['recipient'])
            );

            return [
                'tool' => 'send_email',
                'status' => 'sent',
                'recipient' => $emailDetails['recipient'],
                'subject' => $emailDetails['subject'],
                'attachment_count' => count($attachmentPaths),
                'sent_at' => (new \DateTimeImmutable())->format('c'),
                'result' => $result->getContent()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email', [
                'error' => $e->getMessage(),
                'recipient' => $emailDetails['recipient']
            ]);
            
            throw new \RuntimeException('E-Mail konnte nicht versendet werden: ' . $e->getMessage());
        }
    }

    /**
     * Findet unaufgelöste Platzhalter in Daten
     */
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

    /**
     * Löst Context-Platzhalter in Daten auf
     */
    private function resolveContextPlaceholders(mixed $data, array $context): mixed
    {
        if (is_string($data)) {
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function ($matches) use ($context) {
                    $path = trim($matches[1]);
                    
                    // Unterstütze Pipe-Fallbacks: {{step_2.result.company_name|default_value}}
                    if (str_contains($path, '|')) {
                        $paths = explode('|', $path);
                        foreach ($paths as $fallbackPath) {
                            $value = $this->resolveSinglePath(trim($fallbackPath), $context);
                            if ($value !== null) {
                                $this->logger->debug('Resolved placeholder with fallback', [
                                    'placeholder' => $matches[0],
                                    'path' => trim($fallbackPath),
                                    'value_preview' => substr((string)$value, 0, 100)
                                ]);
                                return $value;
                            }
                        }
                        
                        $this->logger->warning('All fallback paths failed', [
                            'placeholder' => $matches[0],
                            'tried_paths' => $paths,
                            'available_context' => array_keys($context)
                        ]);
                        
                        return $matches[0];
                    }
                    
                    $value = $this->resolveSinglePath($path, $context);
                    
                    if ($value !== null) {
                        $this->logger->debug('Resolved placeholder', [
                            'placeholder' => $matches[0],
                            'path' => $path,
                            'value_preview' => substr((string)$value, 0, 100)
                        ]);
                        return $value;
                    }
                    
                    $this->logger->warning('Placeholder unresolved', [
                        'placeholder' => $matches[0],
                        'path' => $path,
                        'available_context' => array_keys($context)
                    ]);
                    
                    return $matches[0];
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
     * Löst einen einzelnen Pfad im Context auf
     */
    private function resolveSinglePath(string $path, array $context): mixed
    {
        $parts = preg_split('/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $value = $context;
        
        foreach ($parts as $key) {
            if (is_array($value)) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    $this->logger->debug('Context path not found', [
                        'path' => $path,
                        'missing_key' => $key,
                        'available_keys' => array_keys($value)
                    ]);
                    return null;
                }
            } else {
                $this->logger->debug('Cannot traverse non-array', [
                    'path' => $path,
                    'key' => $key,
                    'value_type' => gettype($value)
                ]);
                return null;
            }
        }
        
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Formatiert Bytes in menschenlesbare Form
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < 3; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}