<?php
// src/Entity/TokenUsage.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TokenUsageRepository;

#[ORM\Entity(repositoryClass: TokenUsageRepository::class)]
#[ORM\Table(name: 'token_usage')]
#[ORM\Index(name: 'idx_user_timestamp', columns: ['user_id', 'timestamp'])]
#[ORM\Index(name: 'idx_timestamp', columns: ['timestamp'])]
#[ORM\Index(name: 'idx_user_model_timestamp', columns: ['user_id', 'model', 'timestamp'])]
class TokenUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $model; // gemini-2.5-flash, gpt-4o, claude-sonnet-4, etc.

    #[ORM\Column(type: 'string', length: 50)]
    private string $agentType; // personal_assistant, dev_agent, frontend_generator

    #[ORM\Column(type: 'integer')]
    private int $inputTokens = 0;

    #[ORM\Column(type: 'integer')]
    private int $outputTokens = 0;

    #[ORM\Column(type: 'integer')]
    private int $totalTokens = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $costCents = null; // Cost in cents

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $requestPreview = null; // First 200 chars of request

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $responseTimeMs = null; // Response time in milliseconds

    #[ORM\Column(type: 'boolean')]
    private bool $success = true;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $timestamp;

    public function __construct()
    {
        $this->timestamp = new \DateTimeImmutable();
    }

    // Getters & Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getAgentType(): string
    {
        return $this->agentType;
    }

    public function setAgentType(string $agentType): self
    {
        $this->agentType = $agentType;
        return $this;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function setInputTokens(int $inputTokens): self
    {
        $this->inputTokens = $inputTokens;
        $this->calculateTotalTokens();
        return $this;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function setOutputTokens(int $outputTokens): self
    {
        $this->outputTokens = $outputTokens;
        $this->calculateTotalTokens();
        return $this;
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    private function calculateTotalTokens(): void
    {
        $this->totalTokens = $this->inputTokens + $this->outputTokens;
    }

    public function getCostCents(): ?int
    {
        return $this->costCents;
    }

    public function setCostCents(?int $costCents): self
    {
        $this->costCents = $costCents;
        return $this;
    }

    public function getRequestPreview(): ?string
    {
        return $this->requestPreview;
    }

    public function setRequestPreview(?string $requestPreview): self
    {
        $this->requestPreview = $requestPreview ? mb_substr($requestPreview, 0, 200) : null;
        return $this;
    }

    public function getResponseTimeMs(): ?int
    {
        return $this->responseTimeMs;
    }

    public function setResponseTimeMs(?int $responseTimeMs): self
    {
        $this->responseTimeMs = $responseTimeMs;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }
}