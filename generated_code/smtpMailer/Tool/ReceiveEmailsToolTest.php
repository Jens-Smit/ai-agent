<?php
// tests/Tool/ReceiveEmailsToolTest.php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Entity\User;
use App\Service\MailService;
use App\Tool\ReceiveEmailsTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ReceiveEmailsToolTest extends TestCase
{
    private $logger;
    private $security;
    private $mailService;
    private $user;
    private $receiveEmailsTool;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->user = $this->createMock(User::class);

        $this->receiveEmailsTool = new ReceiveEmailsTool(
            $this->logger,
            $this->security,
            $this->mailService
        );
    }

    public function testInvokePop3Success()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getPop3Host')->willReturn('pop3.example.com');
        $this->user->method('getPop3Port')->willReturn(995);
        $this->user->method('getPop3UseSsl')->willReturn(true);
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $mockEmails = [
            ['id' => 1, 'subject' => 'Test POP3 Email', 'from' => 'sender@example.com', 'date' => '...']
        ];
        $this->mailService->method('receiveEmails')->willReturn(['status' => 'success', 'message' => 'Emails received.', 'emails' => $mockEmails]);
        $this->logger->expects($this->once())->method('info')->with('ReceiveEmailsTool execution started');
        $this->logger->expects($this->once())->method('info')->with('Emails received successfully via ReceiveEmailsTool.', $this->callback(function($arg) {
            return is_array($arg) && isset($arg['protocol']) && isset($arg['count']);
        }));

        $result = $this->receiveEmailsTool('POP3');
        $this->assertEquals(['status' => 'success', 'message' => 'Emails received.', 'emails' => $mockEmails], $result);
    }

    public function testInvokeImapSuccess()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getImapHost')->willReturn('imap.example.com');
        $this->user->method('getImapPort')->willReturn(993);
        $this->user->method('getImapUseSsl')->willReturn(true);
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $mockEmails = [
            ['id' => 2, 'subject' => 'Test IMAP Email', 'from' => 'another@example.com', 'date' => '...']
        ];
        $this->mailService->method('receiveEmails')->willReturn(['status' => 'success', 'message' => 'Emails received.', 'emails' => $mockEmails]);
        $this->logger->expects($this->once())->method('info')->with('ReceiveEmailsTool execution started');
        $this->logger->expects($this->once())->method('info')->with('Emails received successfully via ReceiveEmailsTool.', $this->callback(function($arg) {
            return is_array($arg) && isset($arg['protocol']) && isset($arg['count']);
        }));

        $result = $this->receiveEmailsTool('IMAP');
        $this->assertEquals(['status' => 'success', 'message' => 'Emails received.', 'emails' => $mockEmails], $result);
    }

    public function testInvokeNoAuthenticatedUser()
    {
        $this->security->method('getUser')->willReturn(null);
        $this->logger->expects($this->once())->method('info')->with('ReceiveEmailsTool execution started');
        $this->logger->expects($this->once())->method('error')->with('ReceiveEmailsTool: No authenticated user found.');

        $result = $this->receiveEmailsTool('POP3');
        $this->assertEquals(['status' => 'error', 'message' => 'No authenticated user found.'], $result);
    }

    public function testInvokeIncompletePop3Settings()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getPop3Host')->willReturn(null); // Incomplete setting
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $this->logger->expects($this->once())->method('info')->with('ReceiveEmailsTool execution started');
        $this->logger->expects($this->once())->method('error')->with('ReceiveEmailsTool: Incomplete POP3 settings for user.', ['user_id' => 1]);

        $result = $this->receiveEmailsTool('POP3');
        $this->assertEquals(['status' => 'error', 'message' => 'POP3 settings are incomplete for the authenticated user. Please configure them in your profile.'], $result);
    }

    public function testInvokeIncompleteImapSettings()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getImapHost')->willReturn(null); // Incomplete setting
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $this->logger->expects($this->once())->method('info')->with('ReceiveEmailsTool execution started');
        $this->logger->expects($this->once())->method('error')->with('ReceiveEmailsTool: Incomplete IMAP settings for user.', ['user_id' => 1]);

        $result = $this->receiveEmailsTool('IMAP');
        $this->assertEquals(['status' => 'error', 'message' => 'IMAP settings are incomplete for the authenticated user. Please configure them in your profile.'], $result);
    }

    public function testInvokeMailServiceFailure()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getPop3Host')->willReturn('pop3.example.com');
        $this->user->method('getPop3Port')->willReturn(995);
        $this->user->method('getPop3UseSsl')->willReturn(true);
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $errorMessage = 'Failed to connect to POP3 server.';
        $this->mailService->method('receiveEmails')->willReturn(['status' => 'error', 'message' => $errorMessage]);
        $this->logger->expects($this->once())->method('info')->with('ReceiveEmailsTool execution started');
        $this->logger->expects($this->once())->method('error')->with('Failed to receive emails via ReceiveEmailsTool.', ['error_message' => $errorMessage]);

        $result = $this->receiveEmailsTool('POP3');
        $this->assertEquals(['status' => 'error', 'message' => $errorMessage], $result);
    }
}
