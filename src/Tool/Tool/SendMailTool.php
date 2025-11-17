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

#[AsTool(
    name: 'send_email',
    description: 'Sendet eine E-Mail an einen oder mehrere Empfänger.'
)]
final class SendMailTool
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer // Standard-Mailer, wird überschrieben
    ) {}

    /**
     * Sendet eine E-Mail an einen oder mehrere Empfänger.
     *
     * @param int $userId Die ID des Benutzers, dessen Einstellungen für den E-Mail-Versand verwendet werden sollen.
     * @param string $to Die E-Mail-Adresse des Empfängers. Mehrere Adressen können durch Kommas getrennt werden.
     * @param string $subject Der Betreff der E-Mail.
     * @param string $body Der Inhalt der E-Mail (HTML).
     * @param string|null $from Die Absender-E-Mail-Adresse. Wenn null, wird die in den UserSettings hinterlegte SMTP-Username verwendet.
     * @return array Eine Statusmeldung, ob die E-Mail erfolgreich gesendet wurde.
     */
    public function __invoke(
        #[With(minimum: 1)]
        int $userId,
        #[With(pattern: '/^([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(,\s*)?)+$/')]
        string $to,
        string $subject,
        string $body,
        #[With(pattern: '/^([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})?$/')]
        ?string $from = null
    ): array {
        $this->logger->info('SendMailTool execution started', ['userId' => $userId, 'to' => $to, 'subject' => $subject]);

        try {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user) {
                throw new \InvalidArgumentException(sprintf('User with ID %d not found.', $userId));
            }

            /** @var UserSettings|null $userSettings */
            $userSettings = $user->getUserSettings();
            if (!$userSettings || !$userSettings->getSmtpHost() || !$userSettings->getSmtpPort() || !$userSettings->getSmtpUsername() || !$userSettings->getSmtpPassword()) {
                throw new \RuntimeException('SMTP settings not configured for this user.');
            }

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

            $email = (new Email())
                ->from($from ?: $userSettings->getSmtpUsername())
                ->to(...explode(',', str_replace(' ', '', $to)))
                ->subject($subject)
                ->html($body);

            $customMailer->send($email);

            $this->logger->info('Email sent successfully', ['userId' => $userId, 'to' => $to, 'subject' => $subject]);
            return [
                'status' => 'success',
                'message' => 'E-Mail erfolgreich gesendet.'
            ];
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send email (Transport error)', ['error' => $e->getMessage(), 'userId' => $userId]);
            return ['status' => 'error', 'message' => 'Fehler beim Senden der E-Mail (Transportfehler): ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email', ['error' => $e->getMessage(), 'userId' => $userId]);
            return ['status' => 'error', 'message' => 'Fehler beim Senden der E-Mail: ' . $e->getMessage()];
        }
    }
}
