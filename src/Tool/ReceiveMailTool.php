<?php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Entity\UserSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use PhpImap\Mailbox;
use PhpImap\Exceptions\ConnectionException;

#[AsTool(
    name: 'receive_emails',
    description: 'Empfängt E-Mails für einen Benutzer über IMAP oder POP3.'
)]
final class ReceiveMailTool
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Empfängt eine bestimmte Anzahl von E-Mails für einen Benutzer.
     *
     * @param int $userId Die ID des Benutzers, dessen Einstellungen für den E-Mail-Empfang verwendet werden sollen.
     * @param string $protocol Das zu verwendende Protokoll (IMAP oder POP3). IMAP wird bevorzugt.
     * @param int $limit Die maximale Anzahl der abzurufenden E-Mails.
     * @return array Ein Array von E-Mails, jeweils mit Absender, Betreff, Datum und Inhalt.
     */
    public function __invoke(
        #[With(minimum: 1)]
        int $userId,
        #[With(enum: ['IMAP', 'POP3'])]
        string $protocol,
        #[With(minimum: 1, maximum: 50)]
        int $limit = 10
    ): array {
        $this->logger->info('ReceiveMailTool execution started', ['userId' => $userId, 'protocol' => $protocol, 'limit' => $limit]);

        try {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user) {
                throw new \InvalidArgumentException(sprintf('User with ID %d not found.', $userId));
            }

            /** @var UserSettings|null $userSettings */
            $userSettings = $user->getUserSettings();
            if (!$userSettings) {
                throw new \RuntimeException('Mail settings not configured for this user.');
            }

            $host = '';
            $port = 0;
            $username = '';
            $password = '';
            $encryption = '';

            if ($protocol === 'IMAP') {
                if (!$userSettings->getImapHost() || !$userSettings->getImapPort() || !$userSettings->getImapUsername() || !$userSettings->getImapPassword()) {
                    throw new \RuntimeException('IMAP settings not fully configured for this user.');
                }
                $host = $userSettings->getImapHost();
                $port = $userSettings->getImapPort();
                $username = $userSettings->getImapUsername();
                $password = $userSettings->getImapPassword();
                $encryption = $userSettings->getImapEncryption();
            } elseif ($protocol === 'POP3') {
                if (!$userSettings->getPop3Host() || !$userSettings->getPop3Port() || !$userSettings->getPop3Username() || !$userSettings->getPop3Password()) {
                    throw new \RuntimeException('POP3 settings not fully configured for this user.');
                }
                $host = $userSettings->getPop3Host();
                $port = $userSettings->getPop3Port();
                $username = $userSettings->getPop3Username();
                $password = $userSettings->getPop3Password();
                $encryption = $userSettings->getPop3Encryption();
            } else {
                throw new \InvalidArgumentException('Unsupported protocol. Choose IMAP or POP3.');
            }

            $mailboxPath = sprintf(
                '{%s:%d/%s%s}INBOX',
                $host,
                $port,
                strtolower($protocol),
                $encryption ? '/' . $encryption : ''
            );

            $mailbox = new Mailbox($mailboxPath, $username, $password, __DIR__ . '/../../var/imap_attachments', 'UTF-8');

            $mailsIds = $mailbox->searchMailbox('ALL');
            if (!$mailsIds) {
                return ['status' => 'success', 'message' => 'Keine neuen E-Mails gefunden.', 'emails' => []];
            }

            rsort($mailsIds); // Neueste zuerst
            $fetchedEmails = [];
            foreach (array_slice($mailsIds, 0, $limit) as $mailId) {
                $mail = $mailbox->getMail($mailId, false); // false = keine Anhänge abrufen
                $fetchedEmails[] = [
                    'from' => $mail->fromAddress,
                    'subject' => $mail->subject,
                    'date' => $mail->date,
                    'body_plain' => $mail->textPlain,
                    'body_html' => $mail->textHtml,
                    'message_id' => $mail->messageId
                ];
            }
            $mailbox->disconnect();

            $this->logger->info('Emails fetched successfully', ['userId' => $userId, 'count' => count($fetchedEmails)]);
            return [
                'status' => 'success',
                'message' => sprintf('%d E-Mails erfolgreich abgerufen.', count($fetchedEmails)),
                'emails' => $fetchedEmails
            ];
        } catch (ConnectionException $e) {
            $this->logger->error('Failed to connect to mail server', ['error' => $e->getMessage(), 'userId' => $userId]);
            return ['status' => 'error', 'message' => 'Verbindungsfehler zum E-Mail-Server: ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('Failed to receive emails', ['error' => $e->getMessage(), 'userId' => $userId]);
            return ['status' => 'error', 'message' => 'Fehler beim Abrufen von E-Mails: ' . $e->getMessage()];
        }
    }
}
