<?php

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
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File as MimeFile;
use Symfony\Component\Mailer\Transport;
use Symfony\Bundle\SecurityBundle\Security;

#[AsTool(
    name: 'send_email',
    description: 'Sendet eine E-Mail an einen oder mehrere Empfänger. Kann auch Dateien als Anhang versenden. Anhänge können als Dateipfade (z.B. generierte PDFs) oder als Dokument-IDs aus dem UserDocument-System übergeben werden.'
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
     *
     * @param string $to Die E-Mail-Adresse des Empfängers. Mehrere Adressen können durch Kommas getrennt werden.
     * @param string $subject Der Betreff der E-Mail.
     * @param string $body Der Inhalt der E-Mail (HTML).
     * @param string $attachments Optional: Anhänge als JSON-Array mit Dateipfaden oder Dokument-IDs. Format: [{"type":"path","value":"/var/pdfs/file.pdf"},{"type":"document_id","value":"42"}]
     * @return array Eine Statusmeldung, ob die E-Mail erfolgreich gesendet wurde.
     */
    public function __invoke(
        string $to,
        string $subject,
        string $body,
        string $attachments = ''
    ): array {
        /** @var User|null $user */
        $user = $this->security->getUser();

        if (!$user) {
            $this->logger->warning('SendMailTool failed: No authenticated user found.');
            return ['status' => 'error', 'message' => 'Kein authentifizierter Benutzer gefunden.'];
        }
        
        $userId = $user->getId();
        $this->logger->info('SendMailTool execution started', [
            'userId' => $userId, 
            'to' => $to, 
            'subject' => $subject,
            'has_attachments' => !empty($attachments)
        ]);

        try {
            // 1. Settings vom eingeloggten User laden
            /** @var UserSettings|null $userSettings */
            $userSettings = $user->getUserSettings();

            if (!$userSettings || !$userSettings->getSmtpHost() || !$userSettings->getSmtpPort() || 
                !$userSettings->getSmtpUsername() || !$userSettings->getSmtpPassword()) {
                $this->logger->error('SMTP settings not configured for user', ['userId' => $userId]);
                throw new \RuntimeException('SMTP-Einstellungen sind für diesen Benutzer nicht konfiguriert. Bitte konfigurieren Sie Host, Port, Benutzername und Passwort in Ihren Einstellungen.');
            }

            // 2. Transport DSN dynamisch erstellen
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

            // 3. E-Mail erstellen
            $sender = $userSettings->getSmtpUsername();

            $email = (new Email())
                ->from($sender)
                ->to(...explode(',', str_replace(' ', '', $to)))
                ->subject($subject)
                ->html($body);

            // 4. Anhänge verarbeiten (falls vorhanden)
            $attachmentInfo = [];
            if (!empty($attachments)) {
                $attachmentInfo = $this->processAttachments($email, $attachments, $user);
            }

            // 5. E-Mail senden
            $customMailer->send($email);

            $message = "Email an $to erfolgreich versendet (Absender: $sender) via " . $userSettings->getSmtpHost();
            if (!empty($attachmentInfo)) {
                $message .= "\nAnhänge: " . implode(', ', array_column($attachmentInfo, 'filename'));
            }

            $this->logger->info('Email sent successfully', [
                'userId' => $userId, 
                'to' => $to, 
                'subject' => $subject,
                'attachments' => $attachmentInfo
            ]);

            return [
                'status' => 'success',
                'message' => $message,
                'attachments_sent' => $attachmentInfo
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
     * Verarbeitet Anhänge und fügt sie der E-Mail hinzu
     * 
     * @param Email $email Das E-Mail-Objekt
     * @param string $attachmentsJson JSON-String mit Anhang-Informationen
     * @param User $user Der aktuelle Benutzer
     * @return array Array mit Informationen über verarbeitete Anhänge
     */
    private function processAttachments(Email $email, string $attachmentsJson, User $user): array
    {
        $attachmentInfo = [];

        try {
            // Parse JSON
            $attachments = json_decode($attachmentsJson, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Fallback: Behandle als einfachen Dateipfad
                $attachments = [['type' => 'path', 'value' => $attachmentsJson]];
            }

            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }

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
     * Fügt einen einzelnen Anhang zur E-Mail hinzu
     * 
     * @param Email $email Das E-Mail-Objekt
     * @param mixed $attachment Anhang-Information (Array oder String)
     * @param User $user Der aktuelle Benutzer
     * @return array|null Informationen über den hinzugefügten Anhang
     */
    private function addAttachment(Email $email, mixed $attachment, User $user): ?array
    {
        try {
            // Normalisiere Attachment-Format
            if (is_string($attachment)) {
                $attachment = ['type' => 'path', 'value' => $attachment];
            }

            $type = $attachment['type'] ?? 'path';
            $value = $attachment['value'] ?? '';

            if (empty($value)) {
                return null;
            }

            switch ($type) {
                case 'document_id':
                    return $this->attachDocumentById($email, $value, $user);
                
                case 'path':
                default:
                    return $this->attachFilePath($email, $value);
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
     * 
     * @param Email $email Das E-Mail-Objekt
     * @param string|int $documentId Die Dokument-ID
     * @param User $user Der aktuelle Benutzer
     * @return array|null Informationen über den Anhang
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

        return [
            'type' => 'document',
            'document_id' => $doc->getId(),
            'filename' => $doc->getOriginalFilename(),
            'size' => $doc->getFileSize()
        ];
    }

    /**
     * Fügt eine Datei per Pfad als Anhang hinzu
     * 
     * @param Email $email Das E-Mail-Objekt
     * @param string $filePath Der Dateipfad (relativ oder absolut)
     * @return array|null Informationen über den Anhang
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

        return [
            'type' => 'file',
            'filename' => $filename,
            'path' => $filePath,
            'size' => filesize($filePath)
        ];
    }
}