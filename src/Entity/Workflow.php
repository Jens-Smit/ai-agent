<?php
// src/Entity/Workflow.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\WorkflowRepository;

#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'workflows')]
#[ORM\Index(name: 'idx_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
class Workflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36)]
    private string $sessionId;

    #[ORM\Column(type: 'text')]
    private string $userIntent;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'created'; // created, running, waiting_confirmation, completed, failed, cancelled

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $currentStep = null;

    #[ORM\OneToMany(mappedBy: 'workflow', targetEntity: WorkflowStep::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['stepNumber' => 'ASC'])]
    private Collection $steps;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getUserIntent(): string
    {
        return $this->userIntent;
    }

    public function setUserIntent(string $userIntent): self
    {
        $this->userIntent = $userIntent;
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

    public function getCurrentStep(): ?int
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?int $currentStep): self
    {
        $this->currentStep = $currentStep;
        return $this;
    }

    /**
     * @return Collection<int, WorkflowStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(WorkflowStep $step): self
    {
        if (!$this->steps->contains($step)) {
            $this->steps[] = $step;
            $step->setWorkflow($this);
        }

        return $this;
    }

    public function removeStep(WorkflowStep $step): self
    {
        if ($this->steps->removeElement($step)) {
            if ($step->getWorkflow() === $this) {
                $step->setWorkflow(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
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