<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * EmailService - Auto-generiert vom AI-Agenten
 * 
 * Zweck: Termin-Einladungen per Email versenden
 * Generated: 2025-11-06
 * 
 * Sicherheitsfeatures:
 * - Input-Validierung mit Symfony Validator
 * - User-Approval vor Email-Versand (via Callback)
 * - Rate-Limiting (TODO: implementieren)
 * - Email-Address-Validation
 * - XSS-Protection in Email-Body
 */
class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Sendet eine Termin-Einladung
     * 
     * @param string $recipientEmail Empfänger-Email
     * @param array $meetingDetails ['date', 'time', 'subject', 'location']
     * @param callable|null $approvalCallback Callback für User-Bestätigung
     * @return array Status-Array
     * 
     * @throws \InvalidArgumentException Bei ungültiger Email
     * @throws \RuntimeException Bei Versand-Fehler
     */
    public function sendMeetingInvitation(
        string $recipientEmail,
        array $meetingDetails,
        ?callable $approvalCallback = null
    ): array {
        // Schritt 1: Input-Validierung
        $this->validateEmailAddress($recipientEmail);
        $this->validateMeetingDetails($meetingDetails);

        // Schritt 2: User-Approval (wenn Callback vorhanden)
        if ($approvalCallback !== null) {
            $approved = $approvalCallback([
                'action' => 'send_email',
                'recipient' => $recipientEmail,
                'subject' => $meetingDetails['subject'] ?? 'Termin-Einladung',
                'preview' => $this->generateEmailPreview($meetingDetails)
            ]);

            if (!$approved) {
                $this->logger->info('Email cancelled by user', [
                    'recipient' => $recipientEmail
                ]);

                return [
                    'success' => false,
                    'message' => 'Email-Versand von User abgebrochen',
                    'cancelled_by_user' => true
                ];
            }
        }

        // Schritt 3: Email erstellen (mit XSS-Protection)
        try {
            $email = (new Email())
                ->from('no-reply@yourcompany.com')
                ->to($recipientEmail)
                ->subject($this->sanitize($meetingDetails['subject'] ?? 'Termin-Einladung'))
                ->html($this->generateEmailBody($meetingDetails));

            // Schritt 4: Email senden
            $this->mailer->send($email);

            $this->logger->info('Meeting invitation sent', [
                'recipient' => $recipientEmail,
                'subject' => $meetingDetails['subject'] ?? 'N/A'
            ]);

            return [
                'success' => true,
                'message' => 'Termin-Einladung erfolgreich versendet',
                'recipient' => $recipientEmail,
                'sent_at' => date('c')
            ];

        } catch (\Exception $e) {
            $this->logger->error('Email sending failed', [
                'recipient' => $recipientEmail,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                'Email-Versand fehlgeschlagen: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Validiert Email-Adresse
     * 
     * @throws \InvalidArgumentException
     */
    private function validateEmailAddress(string $email): void
    {
        $constraints = new Assert\Email([
            'message' => 'Die Email-Adresse "{{ value }}" ist ungültig.'
        ]);

        $violations = $this->validator->validate($email, $constraints);

        if (count($violations) > 0) {
            throw new \InvalidArgumentException(
                'Ungültige Email-Adresse: ' . $email
            );
        }

        // Zusätzliche Sicherheitsprüfung: Keine localhost/private IPs
        if (preg_match('/@(localhost|127\.0\.0\.1|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/i', $email)) {
            throw new \InvalidArgumentException(
                'Email-Adresse mit lokaler/privater Domain nicht erlaubt'
            );
        }
    }

    /**
     * Validiert Meeting-Details
     * 
     * @throws \InvalidArgumentException
     */
    private function validateMeetingDetails(array $details): void
    {
        $required = ['date', 'time', 'subject'];

        foreach ($required as $field) {
            if (!isset($details[$field]) || empty($details[$field])) {
                throw new \InvalidArgumentException(
                    "Pflichtfeld '{$field}' fehlt in Meeting-Details"
                );
            }
        }

        // Datum-Validierung
        $date = \DateTime::createFromFormat('Y-m-d', $details['date']);
        if (!$date) {
            throw new \InvalidArgumentException(
                'Ungültiges Datumsformat (erwartet: Y-m-d)'
            );
        }

        // Zeit-Validierung
        if (!preg_match('/^\d{2}:\d{2}$/', $details['time'])) {
            throw new \InvalidArgumentException(
                'Ungültiges Zeitformat (erwartet: HH:MM)'
            );
        }
    }

    /**
     * Generiert Email-Body mit XSS-Protection
     */
    private function generateEmailBody(array $details): string
    {
        $date = $this->sanitize($details['date']);
        $time = $this->sanitize($details['time']);
        $subject = $this->sanitize($details['subject']);
        $location = $this->sanitize($details['location'] ?? 'TBD');

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 10px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .details { margin: 20px 0; }
        .details dt { font-weight: bold; margin-top: 10px; }
        .footer { text-align: center; font-size: 12px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Termin-Einladung</h1>
        </div>
        <div class="content">
            <p>Sie sind eingeladen zu folgendem Termin:</p>
            
            <dl class="details">
                <dt>Thema:</dt>
                <dd>{$subject}</dd>
                
                <dt>Datum:</dt>
                <dd>{$date}</dd>
                
                <dt>Uhrzeit:</dt>
                <dd>{$time} Uhr</dd>
                
                <dt>Ort:</dt>
                <dd>{$location}</dd>
            </dl>
            
            <p>Bitte bestätigen Sie Ihre Teilnahme.</p>
        </div>
        <div class="footer">
            <p>Diese Email wurde automatisch generiert.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generiert Email-Preview für User-Approval
     */
    private function generateEmailPreview(array $details): string
    {
        return sprintf(
            "Termin: %s\nDatum: %s\nUhrzeit: %s\nOrt: %s",
            $details['subject'] ?? 'N/A',
            $details['date'] ?? 'N/A',
            $details['time'] ?? 'N/A',
            $details['location'] ?? 'TBD'
        );
    }

    /**
     * Sanitiert Text (XSS-Protection)
     */
    private function sanitize(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sendet eine simple Email (ohne Meeting-Details)
     * 
     * @param string $to Empfänger
     * @param string $subject Betreff
     * @param string $body Email-Body (wird sanitized)
     * @param callable|null $approvalCallback User-Bestätigung
     * @return array Status
     */
    public function sendSimpleEmail(
        string $to,
        string $subject,
        string $body,
        ?callable $approvalCallback = null
    ): array {
        $this->validateEmailAddress($to);

        if ($approvalCallback !== null) {
            $approved = $approvalCallback([
                'action' => 'send_email',
                'recipient' => $to,
                'subject' => $subject,
                'preview' => substr($body, 0, 200) . '...'
            ]);

            if (!$approved) {
                return [
                    'success' => false,
                    'message' => 'Email-Versand abgebrochen',
                    'cancelled_by_user' => true
                ];
            }
        }

        try {
            $email = (new Email())
                ->from('no-reply@yourcompany.com')
                ->to($to)
                ->subject($this->sanitize($subject))
                ->html($this->sanitize($body));

            $this->mailer->send($email);

            $this->logger->info('Simple email sent', [
                'recipient' => $to,
                'subject' => $subject
            ]);

            return [
                'success' => true,
                'message' => 'Email erfolgreich versendet',
                'recipient' => $to
            ];

        } catch (\Exception $e) {
            $this->logger->error('Email sending failed', [
                'recipient' => $to,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                'Email-Versand fehlgeschlagen: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}