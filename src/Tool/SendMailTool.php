<?php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Entity\UserSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport;
use Symfony\Bundle\SecurityBundle\Security;

#[AsTool(
    name: 'send_email',
    description: 'Sendet eine E-Mail an einen oder mehrere Empfänger.'
)]
final class SendMailTool
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer, // Standard-Mailer, wird überschrieben
        private Security $security
    ) {}

    /**
     * Sendet eine E-Mail an einen oder mehrere Empfänger.
     *
     * @param int $userId Die ID des Benutzers, dessen Einstellungen für den E-Mail-Versand verwendet werden sollen.
     * @param string $to Die E-Mail-Adresse des Empfängers. Mehrere Adressen können durch Kommas getrennt werden.
     * @param string $subject Der Betreff der E-Mail.
     * @param string $body Der Inhalt der E-Mail (HTML).
     * @return array Eine Statusmeldung, ob die E-Mail erfolgreich gesendet wurde.
     */
    public function __invoke(
        
        // #[With(pattern: '/^([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(,\s*)?)+$/')] BEIBEHALTEN (oder entfernt), da es für die String-Validierung nützlich ist.
        // Wenn der Fehler weiterhin auftritt, entfernen Sie dieses Attribut ebenfalls.
       // #[With(pattern: '/^([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(,\s*)?)+$/')]
        string $to,
        string $subject,
        string $body,
      
    ): array {
       /** @var User|null $user */
        $user = $this->security->getUser();

        if (!$user) {
            $this->logger->warning('SendMailTool failed: No authenticated user found.');
            return ['status' => 'error', 'message' => 'Kein authentifizierter Benutzer gefunden.'];
        }
        
        $userId = $user->getId();
        $this->logger->info('SendMailTool execution started', ['userId' => $userId, 'to' => $to, 'subject' => $subject]);

        try {
            // 1. Settings vom eingeloggten User laden
            /** @var UserSettings|null $userSettings */
            $userSettings = $user->getUserSettings();

            if (!$userSettings || !$userSettings->getSmtpHost() || !$userSettings->getSmtpPort() || !$userSettings->getSmtpUsername() || !$userSettings->getSmtpPassword()) {
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

            // 3. E-Mail erstellen und senden
            $sender = $userSettings->getSmtpUsername();

            $email = (new Email())
                ->from($sender)
                ->to(...explode(',', str_replace(' ', '', $to)))
                ->subject($subject)
                ->html($body);

            $customMailer->send($email);

            $this->logger->info('Email sent successfully', ['userId' => $userId, 'to' => $to, 'subject' => $subject]);
            return [
                'status' => 'success',
                'message' => "Email an $to erfolgreich versendet (Absender: $sender) via " . $userSettings->getSmtpHost()
            ];
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send email (Transport error)', ['error' => $e->getMessage(), 'userId' => $userId]);
            return ['status' => 'error', 'message' => 'Fehler beim Senden der E-Mail (Transportfehler): ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email (General error)', ['error' => $e->getMessage(), 'userId' => $userId]);
            return ['status' => 'error', 'message' => 'Fehler beim Senden der E-Mail: ' . $e->getMessage()];
        }
    }
}