<?php
// src/Entity/WorkflowStep.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_steps')]
class WorkflowStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Workflow $workflow = null;

    #[ORM\Column(type: 'integer')]
    private int $stepNumber;

    #[ORM\Column(type: 'string', length: 50)]
    private string $stepType;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $toolName = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $toolParameters = null;

    #[ORM\Column(type: 'boolean')]
    private bool $requiresConfirmation = false;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $emailDetails = null;

    // NEU: Erwartetes Output-Format für strukturierte Analyse
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $expectedOutputFormat = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    // Getter & Setter

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): ?Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(?Workflow $workflow): self
    {
        $this->workflow = $workflow;
        return $this;
    }

    public function getStepNumber(): int
    {
        return $this->stepNumber;
    }

    public function setStepNumber(int $stepNumber): self
    {
        $this->stepNumber = $stepNumber;
        return $this;
    }

    public function getStepType(): string
    {
        return $this->stepType;
    }

    public function setStepType(string $stepType): self
    {
        $this->stepType = $stepType;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function setToolName(?string $toolName): self
    {
        $this->toolName = $toolName;
        return $this;
    }

    public function getToolParameters(): ?array
    {
        return $this->toolParameters;
    }

    public function setToolParameters(?array $toolParameters): self
    {
        $this->toolParameters = $toolParameters;
        return $this;
    }

    public function requiresConfirmation(): bool
    {
        return $this->requiresConfirmation;
    }

    public function setRequiresConfirmation(bool $requiresConfirmation): self
    {
        $this->requiresConfirmation = $requiresConfirmation;
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

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function setResult(?array $result): self
    {
        $this->result = $result;
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

    public function getEmailDetails(): ?array
    {
        return $this->emailDetails;
    }

    public function setEmailDetails(?array $emailDetails): self
    {
        $this->emailDetails = $emailDetails;
        return $this;
    }

    // NEU: Getter & Setter für expectedOutputFormat
    public function getExpectedOutputFormat(): ?array
    {
        return $this->expectedOutputFormat;
    }

    public function setExpectedOutputFormat(?array $expectedOutputFormat): self
    {
        $this->expectedOutputFormat = $expectedOutputFormat;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }
}