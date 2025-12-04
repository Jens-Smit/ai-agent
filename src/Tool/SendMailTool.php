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
     * * HINWEIS: Bei requires_confirmation = true wird diese Methode vom Executor aufgerufen.
     * Wenn der User fehlt, wird der Versand übersprungen und die vorbereiteten Details zurückgegeben.
     */
    public function __invoke(
        string $to,
        string $subject,
        string $body,
        string $attachments = '',
        ?User $user = null
    ): array {
        if (!$user) {
            /** @var User|null $user */
            $user = $this->security->getUser();
        }
        $hasAttachments = !empty(trim($attachments));
        
        $preparedDetails = $this->getPreparedEmailDetails($to, $subject, $body, $attachments, $user);

        if (!$user) {
            $this->logger->warning('SendMailTool: No authenticated user found. Returning prepared details for confirmation.');
            
            // Wichtig: Die Details, die für die E-Mail-Vorschau benötigt werden, zurückgeben.
            // Der Executor MUSS diese Details in das 'email_details' Feld des Steps speichern.
            return [
                'status' => 'prepared', // Neuen Status verwenden, um den Executor zu signalisieren, dass es zur Bestätigung bereit ist.
                'message' => 'E-Mail-Details erfolgreich für die Bestätigung vorbereitet.',
                'prepared_details' => $preparedDetails,
                'sent_to' => $to,
                'subject' => $subject,
                // Füge den vollständigen Body direkt hinzu (damit der Executor ihn speichern kann)
                'body_text' => $body, 
                'attachments' => $preparedDetails['attachments']
            ];
        }
        
        // Wenn ein User vorhanden ist, versuchen wir zu senden
        $userId = $user->getId();
        
        $this->logger->info('SendMailTool execution started', [
            'userId' => $userId, 
            'to' => $to, 
            'subject' => $subject,
            'body_length' => strlen($body),
            'has_attachments' => $hasAttachments
        ]);

        try {
            // 1. Settings vom User laden
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
                $userSettings->getSmtpPort()
            );

            if ($userSettings->getSmtpEncryption()) {
                $transportDsn .= '?encryption=' . $userSettings->getSmtpEncryption();
            }

            $customTransport = Transport::fromDsn($transportDsn);
            $customMailer = new \Symfony\Component\Mailer\Mailer($customTransport);

            // 3. E-Mail erstellen und Anhänge hinzufügen (unter Verwendung der vorbereiteten Details)
            $email = $preparedDetails['email'];
            $attachmentInfo = $preparedDetails['attachments'];
            $sender = $userSettings->getSmtpUsername();

            // Setze Absender, da dieser erst hier bekannt ist
            $email->from($sender);

            // 4. E-Mail senden
            $customMailer->send($email);

            $message = sprintf('E-Mail erfolgreich an %s versendet (Absender: %s)', $to, $sender);
            if (!empty($attachmentInfo)) {
                $attachmentNames = array_column($attachmentInfo, 'filename');
                $message .= sprintf(' mit %d Anhang(en): %s', 
                    count($attachmentInfo), 
                    implode(', ', $attachmentNames)
                );
            }

            // ... (Logger-Info bleibt gleich)

            return [
                'status' => 'success',
                'message' => $message,
                'sent_to' => $to,
                'attachments_sent' => count($attachmentInfo),
                'attachment_details' => $attachmentInfo
            ];

        } catch (TransportExceptionInterface $e) {
            // ... (Fehlerbehandlung bleibt gleich)
            $this->logger->error('Failed to send email (Transport error)', [
                'error' => $e->getMessage(), 
                'userId' => $userId
            ]);
            return [
                'status' => 'error', 
                'message' => 'Fehler beim Senden der E-Mail (Transportfehler): ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            // ... (Fehlerbehandlung bleibt gleich)
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
     * Kann auch von der WorkflowExecutor::prepareSendMailDetails verwendet werden.
     */
    // In src/Tool/SendMailTool.php, Methode getPreparedEmailDetails:

    public function getPreparedEmailDetails(string $to, string $subject, string $body, string $attachmentsJson, ?User $user): array
    {
        $this->logger->debug('Starting getPreparedEmailDetails', ['user_present' => (bool)$user, 'attachments_json_length' => strlen($attachmentsJson)]);
        
        // 1. E-Mail erstellen (ohne Absender, da dieser erst beim Senden bekannt ist)
        $formattedBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        $email = (new Email())
            ->to(...explode(',', str_replace(' ', '', $to)))
            ->subject($subject)
            ->html($formattedBody);
        
        // 2. Anhänge Metadaten verarbeiten (KEIN Anhang-Hinzufügen zum Email-Objekt)
        $attachmentInfo = [];
        if (!empty(trim($attachmentsJson))) {
            
            // Lade die Attachment-Metadaten (nur für die Vorschau)
            try {
                $attachmentData = json_decode($attachmentsJson, true);
                if (is_array($attachmentData)) {
                    // Normalisiere die Metadaten
                    $attachmentInfo = array_map(function($a) {
                        // Wenn es bereits das vollständige Format ist, verwende es.
                        if (isset($a['filename'])) {
                            return $a;
                        }
                        // Ansonsten, normales Roh-Format (wird später in processAttachments aufgelöst)
                        return ['type' => $a['type'] ?? 'document_id', 'value' => (string)$a['value']];
                    }, $attachmentData);
                }
            } catch (\Exception $e) { 
                $this->logger->debug('JSON error parsing attachment metadata', ['error' => $e->getMessage()]);
            }
            
            // HIER FEHLTE DER SCHLIESSENDE BLOCK IN DER VORHERIGEN ANTWORT.
            // Die Logik von processAttachments wurde komplett in den Sende-Block in __invoke verschoben.
            // Daher ist hier kein weiterer Code für Anhänge nötig.
        }
        
        $this->logger->debug('Finished getPreparedEmailDetails', ['email_object_hash' => spl_object_hash($email), 'attachments_metadata_count' => count($attachmentInfo)]);

        // Da die Anhänge direkt zum $email Objekt hinzugefügt werden,
        // geben wir das Email-Objekt selbst und die Metadaten zurück.
        return [
            'email' => $email,
            'attachments' => $attachmentInfo,
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
                // Prüfe ob es eine reine Zahl ist (document_id)
                if (is_numeric($attachment)) {
                    $attachment = ['type' => 'document_id', 'value' => $attachment];
                } 
                // Prüfe ob es ein Dateipfad ist (enthält / oder \)
                elseif (str_contains($attachment, '/') || str_contains($attachment, '\\')) {
                    $attachment = ['type' => 'path', 'value' => $attachment];
                }
                // Default: behandle als document_id
                else {
                    $attachment = ['type' => 'document_id', 'value' => $attachment];
                }
            }

            $type = $attachment['type'] ?? 'document_id';
            $value = $attachment['value'] ?? '';

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