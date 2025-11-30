<?php
// src/Service/Workflow/Executor/ToolExecutionTrait.php

declare(strict_types=1);

namespace App\Service\Workflow\Executor;

use App\Entity\User;
use App\Entity\WorkflowStep;
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
        $parameters = $step->getToolParameters(); // ✅ Bereits aufgelöst in executeStep

        $this->logger->info('Executing tool call', [
            'tool' => $toolName,
            'parameters' => $parameters,
            'context_keys' => array_keys($context),
            'has_user' => $user !== null
        ]);

        // ✅ E-Mail-Vorbereitung
        if ($toolName === 'send_email' || $toolName === 'SendMailTool') {
            return $this->prepareSendMailDetails($step, $parameters, $sessionId, $context, $user);
        }

        // Andere Tools: User-Kontext erforderlich
        if ($toolName === 'company_career_contact_finder') {
            return $this->executeCompanyContactFinderWithFallback($step, $parameters, $context, $sessionId);
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

    private function isContactFinderSuccessful(array $result): bool
    {
        if (isset($result['success']) && $result['success'] === true) {
            return true;
        }

        return !empty($result['application_email']) || !empty($result['general_email']);
    }

    /**
     * Bereitet E-Mail vor - KEIN Fehler wenn User fehlt
     */
    private function prepareSendMailDetails(
        WorkflowStep $step,
        array $parameters,
        string $sessionId,
        array $context,
        ?User $user
    ): array {
        $recipient = $parameters['to'] ?? 'Unbekannt';
        $subject = $parameters['subject'] ?? 'Kein Betreff';
        $body = $parameters['body'] ?? '';
        $attachmentIds = [];

        if (isset($parameters['attachments'])) {
            $attachmentsParam = $parameters['attachments'];
            
            if (is_string($attachmentsParam)) {
                $decoded = json_decode($attachmentsParam, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $attachmentIds = $decoded;
                } else {
                    $attachmentIds = array_filter(array_map('trim', explode(',', $attachmentsParam)));
                }
            } elseif (is_array($attachmentsParam)) {
                $attachmentIds = $attachmentsParam;
            }
        }

        $this->logger->info('Preparing email details', [
            'recipient' => $recipient,
            'attachment_ids' => $attachmentIds,
            'has_user' => $user !== null
        ]);

        $attachmentDetails = [];
        if ($user !== null && !empty($attachmentIds)) {
            foreach ($attachmentIds as $attachment) {
                if (empty($attachment)) continue;

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
                }
            }
        } elseif ($user === null && !empty($attachmentIds)) {
            foreach ($attachmentIds as $attachment) {
                $docId = is_array($attachment) ? ($attachment['value'] ?? $attachment['id'] ?? null) : $attachment;
                if ($docId) {
                    $attachmentDetails[] = [
                        'id' => $docId,
                        'filename' => "Dokument #{$docId}",
                        'size' => 0,
                        'size_human' => 'Unbekannt',
                        'mime_type' => 'application/octet-stream',
                        'type' => 'unknown',
                        'download_url' => sprintf('/api/documents/%s/download', $docId),
                        'placeholder' => true
                    ];
                }
            }
        }

        $emailDetails = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'body_preview' => mb_substr(strip_tags($body), 0, 200) . (mb_strlen($body) > 200 ? '...' : ''),
            'body_length' => mb_strlen($body),
            'attachments' => $attachmentDetails,
            'attachment_count' => count($attachmentDetails),
            'ready_to_send' => $user !== null,
            'requires_user_context' => $user === null,
            'prepared_at' => (new \DateTimeImmutable())->format('c'),
            '_original_params' => $parameters,
            '_user_id' => $user?->getId()
        ];

        $step->setEmailDetails($emailDetails);
        $step->setStatus('pending_confirmation');
        $step->setRequiresConfirmation(true);
        
        $this->em->flush();

        $attachmentInfo = count($attachmentDetails) > 0 
            ? sprintf(' (%d Anhänge)', count($attachmentDetails))
            : '';

        $statusMessage = $user !== null
            ? sprintf('📧 E-Mail vorbereitet an %s - Betreff: "%s"%s (wartet auf Freigabe)', 
                $recipient, 
                mb_substr($subject, 0, 50),
                $attachmentInfo
            )
            : sprintf('📧 E-Mail vorbereitet an %s - User-Kontext erforderlich für finale Freigabe', $recipient);

        $this->statusService->addStatus($sessionId, $statusMessage);

        return [
            'tool' => 'send_email',
            'status' => 'prepared',
            'email_details' => $emailDetails,
            'requires_user_authentication' => $user === null
        ];
    }

    /**
     * Versendet vorbereitete E-Mail mit User-Reload
     */
    private function executeSendEmail(WorkflowStep $step, string $sessionId, ?User $user): array
    {
        $emailDetails = $step->getEmailDetails();

        if (!$emailDetails || !isset($emailDetails['_original_params'])) {
            throw new \RuntimeException('Email details not found or incomplete');
        }

        if (!$user && isset($emailDetails['_user_id'])) {
            $user = $this->em->getRepository(User::class)->find($emailDetails['_user_id']);
            
            if (!$user) {
                throw new \RuntimeException('User not found - cannot send email');
            }
            
            $this->logger->info('User context restored from email details', [
                'user_id' => $user->getId()
            ]);
        }

        if (!$user) {
            throw new \RuntimeException('User context required for sending email');
        }

        $attachmentDetails = $emailDetails['attachments'] ?? [];
        $hasPlaceholders = !empty(array_filter($attachmentDetails, fn($a) => $a['placeholder'] ?? false));

        if ($hasPlaceholders) {
            $this->logger->info('Resolving placeholder attachments', [
                'count' => count($attachmentDetails)
            ]);
            
            $resolvedAttachments = [];
            foreach ($attachmentDetails as $attachment) {
                if (isset($attachment['id'])) {
                    $document = $this->documentRepo->find($attachment['id']);
                    if ($document && $document->getUser()->getId() === $user->getId()) {
                        $resolvedAttachments[] = [
                            'id' => $document->getId(),
                            'filename' => $document->getOriginalFilename(),
                            'size' => $document->getFileSize(),
                            'size_human' => $this->formatBytes($document->getFileSize()),
                            'mime_type' => $document->getMimeType(),
                            'type' => $document->getDocumentType(),
                            'path' => $document->getFullPath()
                        ];
                    }
                }
            }
            $attachmentDetails = $resolvedAttachments;
        }

        $attachmentPaths = [];
        foreach ($attachmentDetails as $attachment) {
            if (isset($attachment['path'])) {
                $attachmentPaths[] = $attachment['path'];
            } else {
                $document = $this->documentRepo->find($attachment['id']);
                if ($document && $document->getUser()->getId() === $user->getId()) {
                    $attachmentPaths[] = $document->getFullPath();
                }
            }
        }

        $params = $emailDetails['_original_params'];
        $toolParams = [
            'to' => $params['to'],
            'subject' => $params['subject'],
            'body' => $params['body'],
            'attachments' => $attachmentPaths
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
     * ✅ SIMPLIFIED: Nutzt ContextResolver wenn verfügbar, sonst Legacy-Methode
     */
    private function resolveContextPlaceholders(mixed $data, array $context): mixed
    {
        // ✅ Nutze ContextResolver aus Parent-Class wenn vorhanden
        if (isset($this->contextResolver)) {
            return $this->contextResolver->resolveAll($data, $context);
        }

        // Fallback: Legacy-Methode (für Kompatibilität)
        return $this->resolveContextPlaceholdersLegacy($data, $context);
    }

    /**
     * Legacy Platzhalter-Auflösung (Fallback)
     */
    private function resolveContextPlaceholdersLegacy(mixed $data, array $context): mixed
    {
        if (is_string($data)) {
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function ($matches) use ($context) {
                    $path = trim($matches[1]);
                    
                    if (str_contains($path, '|')) {
                        $paths = explode('|', $path);
                        foreach ($paths as $fallbackPath) {
                            $value = $this->resolveSinglePath(trim($fallbackPath), $context);
                            if ($value !== null) {
                                return $value;
                            }
                        }
                        return $matches[0];
                    }
                    
                    $value = $this->resolveSinglePath($path, $context);
                    return $value ?? $matches[0];
                },
                $data
            );
        }

        if (is_array($data)) {
            return array_map(fn($item) => $this->resolveContextPlaceholdersLegacy($item, $context), $data);
        }

        return $data;
    }

    private function resolveSinglePath(string $path, array $context): mixed
    {
        $parts = preg_split('/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $value = $context;
        
        foreach ($parts as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        
        return json_encode($value, JSON_UNESCAPED_UNICODE);
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