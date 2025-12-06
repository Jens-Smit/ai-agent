<?php
// src/Tool/SendMailTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Entity\UserSettings;
use App\Repository\UserDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport;
use Symfony\Bundle\SecurityBundle\Security;

#[AsTool(
    name: 'send_email',
    description: 'Sendet eine E-Mail an einen oder mehrere Empfänger. Attachments sind OPTIONAL - verwende sie nur wenn wirklich benötigt. Der Body kann auch längeren Text enthalten (z.B. Bewerbungsschreiben).'
)]
final class SendMailTool
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private Security $security,
        private UserDocumentRepository $documentRepo,
        private string $projectRootDir
    ) {}

    /**
     * Sendet eine E-Mail an einen oder mehrere Empfänger mit optionalen Anhängen.
     */
    public function __invoke(
        string $to,
        string $subject,
        string $body,
        string $attachments = ''
    ): array {
        
        $user = $this->security->getUser();
        
        if (!$user) {
            $this->logger->warning('SendMailTool: No authenticated user found. Returning prepared details for confirmation.');
            
            return [
                'status' => 'prepared',
                'message' => 'E-Mail-Details erfolgreich für die Bestätigung vorbereitet.',
                'prepared_details' => $this->getPreparedEmailDetails($to, $subject, $body, $attachments, null),
                'sent_to' => $to,
                'subject' => $subject,
                'body_text' => $body,
            ];
        }
        
        $userId = $user->getId();
       
        $this->logger->info('SendMailTool execution started', [
            'userId' => $userId, 
            'to' => $to, 
            'subject' => $subject,
            'body_length' => strlen($body),
            'has_attachments' => !empty(trim($attachments))
        ]);

        try {
            // 1. SMTP Settings laden
            /** @var UserSettings|null $userSettings */
            $userSettings = $user->getUserSettings();

            if (!$userSettings || !$userSettings->getSmtpHost() || !$userSettings->getSmtpPort() || 
                !$userSettings->getSmtpUsername() || !$userSettings->getSmtpPassword()) {
                $this->logger->error('SMTP settings not configured for user', ['userId' => $userId]);
                throw new \RuntimeException('SMTP-Einstellungen sind für diesen Benutzer nicht konfiguriert.');
            }

            // 2. Transport DSN erstellen
            $transportDsn = sprintf(
                'smtp://%s:%s@%s:%d',
                urlencode($userSettings->getSmtpUsername()),
                urlencode($userSettings->getSmtpPassword()),
                $userSettings->getSmtpHost(),
                $userSettings->getSmtpPort(),
            );

            if ($userSettings->getSmtpEncryption()) {
                $transportDsn .= '?encryption=' . $userSettings->getSmtpEncryption();
            }

            $this->logger->info('Using Custom SMTP DSN', [
                'dsn' => preg_replace('/:[^:@]+@/', ':***@', $transportDsn), // Password maskieren
                'host' => $userSettings->getSmtpHost(),
                'port' => $userSettings->getSmtpPort()
            ]);

            $customTransport = Transport::fromDsn($transportDsn);
            $customMailer = new \Symfony\Component\Mailer\Mailer($customTransport);

            // 3. E-Mail erstellen
            $formattedBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
            $sender = $userSettings->getSmtpUsername();

            $email = (new Email())
                ->from($sender)
                ->to(...explode(',', str_replace(' ', '', $to)))
                ->subject($subject)
                ->html($formattedBody);

            // 4. 🔧 FIX: Attachments hinzufügen
            $attachmentInfo = [];
            if (!empty(trim($attachments))) {
                $this->logger->debug('Processing attachments', ['attachments_json' => $attachments]);
                $attachmentInfo = $this->processAttachments($email, $attachments, $user);
            }

            // 5. E-Mail senden
            $this->logger->info('Sending email now...', [
                'to' => $to,
                'from' => $sender,
                'attachment_count' => count($attachmentInfo)
            ]);

            $customMailer->send($email);

            $message = sprintf('E-Mail erfolgreich an %s versendet (Absender: %s)', $to, $sender);
            if (!empty($attachmentInfo)) {
                $attachmentNames = array_column($attachmentInfo, 'filename');
                $message .= sprintf(' mit %d Anhang(en): %s', 
                    count($attachmentInfo), 
                    implode(', ', $attachmentNames)
                );
            }

            $this->logger->info('Email sent successfully', [
                'to' => $to,
                'attachments' => count($attachmentInfo)
            ]);

            return [
                'status' => 'success',
                'message' => $message,
                'sent_to' => $to,
                'attachments_sent' => count($attachmentInfo),
                'attachment_details' => $attachmentInfo
            ];

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send email (Transport error)', [
                'error' => $e->getMessage(), 
                'userId' => $userId
            ]);
            return [
                'status' => 'error', 
                'message' => 'Fehler beim Senden der E-Mail (Transportfehler): ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email (General error)', [
                'error' => $e->getMessage(), 
                'userId' => $userId,
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 'error', 
                'message' => 'Fehler beim Senden der E-Mail: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Bereitet die E-Mail-Details (ohne User/SMTP-Check) vor.
     */
    public function getPreparedEmailDetails(string $to, string $subject, string $body, string $attachmentsJson, ?User $user): array
    {
        $attachmentInfo = [];
        
        if (!empty(trim($attachmentsJson))) {
            try {
                $attachmentData = json_decode($attachmentsJson, true);
                if (is_array($attachmentData) && $user) {
                    foreach ($attachmentData as $a) {
                        if (isset($a['value'])) {
                            $doc = $this->documentRepo->find((int)$a['value']);
                            if ($doc && $doc->getUser() === $user) {
                                $attachmentInfo[] = [
                                    'id' => $doc->getId(),
                                    'filename' => $doc->getOriginalFilename(),
                                    'size' => $doc->getFileSize()
                                ];
                            }
                        }
                    }
                }
            } catch (\Exception $e) { 
                $this->logger->debug('JSON error parsing attachment metadata', ['error' => $e->getMessage()]);
            }
        }

        return [
            'recipient' => $to,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $attachmentInfo
        ];
    }

    /**
     * Verarbeitet Anhänge - Unterstützt verschiedene Formate
     */
    private function processAttachments(Email $email, string $attachmentsJson, User $user): array
    {
        $attachmentInfo = [];

        try {
            // Parse JSON
            $attachments = json_decode($attachmentsJson, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Fallback 1: Komma-separierte IDs
                if (str_contains($attachmentsJson, ',')) {
                    $attachments = array_map('trim', explode(',', $attachmentsJson));
                } else {
                    // Fallback 2: Einzelner Wert
                    $attachments = [$attachmentsJson];
                }
            }

            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }

            $this->logger->debug('Processing attachments', [
                'count' => count($attachments),
                'raw' => $attachmentsJson
            ]);

            foreach ($attachments as $attachment) {
                $attachmentResult = $this->addAttachment($email, $attachment, $user);
                if ($attachmentResult) {
                    $attachmentInfo[] = $attachmentResult;
                }
            }

        } catch (\Exception $e) {
            $this->logger->warning('Failed to process attachments', [
                'error' => $e->getMessage(),
                'attachments' => $attachmentsJson
            ]);
        }

        return $attachmentInfo;
    }

    /**
     * Fügt einen einzelnen Anhang hinzu - Erkennt automatisch das Format
     */
    private function addAttachment(Email $email, mixed $attachment, User $user): ?array
    {
        try {
            // Normalisiere zu Array-Format
            if (is_string($attachment)) {
                if (is_numeric($attachment)) {
                    $attachment = ['type' => 'document_id', 'value' => $attachment];
                } 
                elseif (str_contains($attachment, '/') || str_contains($attachment, '\\')) {
                    $attachment = ['type' => 'path', 'value' => $attachment];
                }
                else {
                    $attachment = ['type' => 'document_id', 'value' => $attachment];
                }
            }

            $value = $attachment['value'] ?? $attachment['id'] ?? '';
            $type = $attachment['type'] ?? 'document_id';

            if (empty($value)) {
                $this->logger->debug('Empty attachment value, skipping');
                return null;
            }

            $this->logger->debug('Adding attachment', [
                'type' => $type,
                'value' => $value
            ]);

            switch ($type) {
                case 'document_id':
                    return $this->attachDocumentById($email, $value, $user);
                
                case 'path':
                    return $this->attachFilePath($email, $value);
                
                default:
                    $this->logger->warning('Unknown attachment type', ['type' => $type]);
                    return null;
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to add attachment', [
                'error' => $e->getMessage(),
                'attachment' => $attachment
            ]);
            return null;
        }
    }

    /**
     * Fügt ein UserDocument als Anhang hinzu
     */
    private function attachDocumentById(Email $email, string|int $documentId, User $user): ?array
    {
        $doc = $this->documentRepo->find((int)$documentId);

        if (!$doc || $doc->getUser() !== $user) {
            $this->logger->warning('Document not found or access denied', [
                'document_id' => $documentId,
                'user_id' => $user->getId()
            ]);
            return null;
        }

        $fullPath = $doc->getFullPath();

        if (!file_exists($fullPath)) {
            $this->logger->warning('Document file does not exist', ['path' => $fullPath]);
            return null;
        }

        $email->attachFromPath($fullPath, $doc->getOriginalFilename(), $doc->getMimeType());

        $this->logger->info('Document attached successfully', [
            'document_id' => $doc->getId(),
            'filename' => $doc->getOriginalFilename()
        ]);

        return [
            'type' => 'document',
            'document_id' => $doc->getId(),
            'filename' => $doc->getOriginalFilename(),
            'size' => $doc->getFileSize()
        ];
    }

    /**
     * Fügt eine Datei per Pfad als Anhang hinzu
     */
    private function attachFilePath(Email $email, string $filePath): ?array
    {
        // Konvertiere relativen Pfad zu absolutem
        if (!str_starts_with($filePath, '/') && !preg_match('/^[A-Z]:/i', $filePath)) {
            $filePath = $this->projectRootDir . '/' . ltrim($filePath, '/');
        }

        if (!file_exists($filePath)) {
            $this->logger->warning('Attachment file does not exist', ['path' => $filePath]);
            return null;
        }

        if (!is_readable($filePath)) {
            $this->logger->warning('Attachment file is not readable', ['path' => $filePath]);
            return null;
        }

        $filename = basename($filePath);
        $email->attachFromPath($filePath, $filename);

        $this->logger->info('File attached successfully', [
            'filename' => $filename,
            'path' => $filePath
        ]);

        return [
            'type' => 'file',
            'filename' => $filename,
            'path' => $filePath,
            'size' => filesize($filePath)
        ];
    }
}