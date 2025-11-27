<?php
// src/Entity/WorkflowExecution.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\WorkflowExecutionRepository;

/**
 * Speichert jeden einzelnen Workflow-Durchlauf für History & Debugging
 */
#[ORM\Entity(repositoryClass: WorkflowExecutionRepository::class)]
#[ORM\Table(name: 'workflow_executions')]
#[ORM\Index(name: 'idx_workflow', columns: ['workflow_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_started', columns: ['started_at'])]
class WorkflowExecution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class, inversedBy: 'executions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'running'; // running, completed, failed, cancelled, waiting_user_input

    #[ORM\Column(type: 'integer')]
    private int $executionNumber;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stepResults = null; // Snapshot aller Step-Ergebnisse

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null; // Workflow-Context während Execution

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationSeconds = null;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function complete(): void
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->status = 'completed';
        $this->durationSeconds = $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function fail(string $errorMessage): void
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
        $this->durationSeconds = $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function pauseForUserInput(): void
    {
        $this->status = 'waiting_user_input';
    }

    // Getters & Setters
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(Workflow $workflow): self
    {
        $this->workflow = $workflow;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getExecutionNumber(): int
    {
        return $this->executionNumber;
    }

    public function setExecutionNumber(int $executionNumber): self
    {
        $this->executionNumber = $executionNumber;
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

    public function getStepResults(): ?array
    {
        return $this->stepResults;
    }

    public function setStepResults(?array $stepResults): self
    {
        $this->stepResults = $stepResults;
        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }
}