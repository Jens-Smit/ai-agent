<?php
// tests/Tool/SendEmailToolTest.php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Entity\User;
use App\Service\MailService;
use App\Tool\SendEmailTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class SendEmailToolTest extends TestCase
{
    private $logger;
    private $security;
    private $mailService;
    private $user;
    private $sendEmailTool;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->user = $this->createMock(User::class);

        $this->sendEmailTool = new SendEmailTool(
            $this->logger,
            $this->security,
            $this->mailService
        );
    }

    public function testInvokeSuccess()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getSmtpHost')->willReturn('smtp.example.com');
        $this->user->method('getSmtpPort')->willReturn(587);
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $this->mailService->method('sendEmail')->willReturn(['status' => 'success', 'message' => 'Email sent.']);
        $this->logger->expects($this->once())->method('info')->with('SendEmailTool execution started');
        $this->logger->expects($this->once())->method('info')->with('Email sent successfully via SendEmailTool.', $this->callback(function($arg) {
            return is_array($arg) && isset($arg['to']) && isset($arg['subject']);
        }));

        $result = $this->sendEmailTool('recipient@example.com', 'Test Subject', 'Test Body');
        $this->assertEquals(['status' => 'success', 'message' => 'Email sent.'], $result);
    }

    public function testInvokeNoAuthenticatedUser()
    {
        $this->security->method('getUser')->willReturn(null);
        $this->logger->expects($this->once())->method('info')->with('SendEmailTool execution started');
        $this->logger->expects($this->once())->method('error')->with('SendEmailTool: No authenticated user found.');

        $result = $this->sendEmailTool('recipient@example.com', 'Test Subject', 'Test Body');
        $this->assertEquals(['status' => 'error', 'message' => 'No authenticated user found.'], $result);
    }

    public function testInvokeIncompleteSmtpSettings()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getSmtpHost')->willReturn(null); // Incomplete setting

        $this->logger->expects($this->once())->method('info')->with('SendEmailTool execution started');
        $this->logger->expects($this->once())->method('error')->with('SendEmailTool: Incomplete SMTP settings for user.', ['user_id' => 1]);

        $result = $this->sendEmailTool('recipient@example.com', 'Test Subject', 'Test Body');
        $this->assertEquals(['status' => 'error', 'message' => 'SMTP settings are incomplete for the authenticated user. Please configure them in your profile.'], $result);
    }

    public function testInvokeMailServiceFailure()
    {
        $this->security->method('getUser')->willReturn($this->user);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getSmtpHost')->willReturn('smtp.example.com');
        $this->user->method('getSmtpPort')->willReturn(587);
        $this->user->method('getEmailUsername')->willReturn('user@example.com');
        $this->user->method('getEmailPassword')->willReturn('password');

        $errorMessage = 'Failed to connect to SMTP server.';
        $this->mailService->method('sendEmail')->willReturn(['status' => 'error', 'message' => $errorMessage]);
        $this->logger->expects($this->once())->method('info')->with('SendEmailTool execution started');
        $this->logger->expects($this->once())->method('error')->with('Failed to send email via SendEmailTool.', ['error_message' => $errorMessage]);


        $result = $this->sendEmailTool('recipient@example.com', 'Test Subject', 'Test Body');
        $this->assertEquals(['status' => 'error', 'message' => $errorMessage], $result);
    }
}
