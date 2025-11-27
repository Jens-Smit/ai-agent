<?php
// src/Entity/Workflow.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\WorkflowRepository;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'workflows')]
#[ORM\Index(name: 'idx_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_schedule', columns: ['is_scheduled', 'next_run_at'])]
class Workflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['workflow:read'])] // Gruppe für die ID
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 512)]
    #[Groups(['workflow:read'])]
    private string $sessionId;

    #[ORM\Column(type: 'text')]
    #[Groups(['workflow:read'])]
    private string $userIntent;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['workflow:read'])]
    private string $status = 'draft'; // draft, approved, running, waiting_user_input, completed, failed, cancelled

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?int $currentStep = null;

    #[ORM\OneToMany(mappedBy: 'workflow', targetEntity: WorkflowStep::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['stepNumber' => 'ASC'])]
    private Collection $steps;

    // ✅ NEU: Scheduling Features
    #[ORM\Column(type: 'boolean')]
    #[Groups(['workflow:read'])]
    private bool $isScheduled = false;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Groups(['workflow:read'])]
    private ?string $scheduleType = null; // once, hourly, daily, weekly, biweekly, monthly, custom

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $scheduleConfig = null; // z.B. {"time": "12:00", "day_of_week": "monday", "day_of_month": 1}

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['workflow:read'])]
    private int $executionCount = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxExecutions = null; // null = unbegrenzt

    // ✅ NEU: Approval & Replay Features
    #[ORM\Column(type: 'boolean')]
    #[Groups(['workflow:read'])]
    private bool $requiresApproval = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?int $approvedBy = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['workflow:read'])]
    private bool $isTemplate = false; // Kann als Template gespeichert werden

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $templateName = null;

    // ✅ NEU: User Interaction für Fehler
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $userInteractionRequired = null; // Speichert welche Steps User-Input brauchen

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?string $userInteractionMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['workflow:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['workflow:read'])]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\OneToMany(mappedBy: 'workflow', targetEntity: WorkflowExecution::class)]
    private Collection $executions;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
        $this->executions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    // ✅ Scheduling Methods
    
    public function scheduleOnce(\DateTimeImmutable $runAt): self
    {
        $this->isScheduled = true;
        $this->scheduleType = 'once';
        $this->nextRunAt = $runAt;
        return $this;
    }

    public function scheduleHourly(int $minutePastHour = 0): self
    {
        $this->isScheduled = true;
        $this->scheduleType = 'hourly';
        $this->scheduleConfig = ['minute' => $minutePastHour];
        $this->calculateNextRun();
        return $this;
    }

    public function scheduleDaily(string $time = '12:00'): self
    {
        $this->isScheduled = true;
        $this->scheduleType = 'daily';
        $this->scheduleConfig = ['time' => $time];
        $this->calculateNextRun();
        return $this;
    }

    public function scheduleWeekly(string $dayOfWeek, string $time = '12:00'): self
    {
        $this->isScheduled = true;
        $this->scheduleType = 'weekly';
        $this->scheduleConfig = [
            'day_of_week' => $dayOfWeek,
            'time' => $time
        ];
        $this->calculateNextRun();
        return $this;
    }

    public function scheduleBiweekly(string $dayOfWeek, string $time = '12:00'): self
    {
        $this->isScheduled = true;
        $this->scheduleType = 'biweekly';
        $this->scheduleConfig = [
            'day_of_week' => $dayOfWeek,
            'time' => $time,
            'start_week' => (new \DateTimeImmutable())->format('W')
        ];
        $this->calculateNextRun();
        return $this;
    }

    public function scheduleMonthly(int $dayOfMonth, string $time = '12:00'): self
    {
        $this->isScheduled = true;
        $this->scheduleType = 'monthly';
        $this->scheduleConfig = [
            'day_of_month' => $dayOfMonth,
            'time' => $time
        ];
        $this->calculateNextRun();
        return $this;
    }

    public function calculateNextRun(): void
    {
        if (!$this->isScheduled || !$this->scheduleType) {
            return;
        }

        $now = new \DateTimeImmutable();
        
        match($this->scheduleType) {
            'once' => null, // Bereits gesetzt
            'hourly' => $this->nextRunAt = $this->calculateNextHourly($now),
            'daily' => $this->nextRunAt = $this->calculateNextDaily($now),
            'weekly' => $this->nextRunAt = $this->calculateNextWeekly($now),
            'biweekly' => $this->nextRunAt = $this->calculateNextBiweekly($now),
            'monthly' => $this->nextRunAt = $this->calculateNextMonthly($now),
            default => null
        };
    }

    private function calculateNextHourly(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $minute = $this->scheduleConfig['minute'] ?? 0;
        $next = $now->setTime((int)$now->format('H'), $minute, 0);
        
        if ($next <= $now) {
            $next = $next->modify('+1 hour');
        }
        
        return $next;
    }

    private function calculateNextDaily(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $time = $this->scheduleConfig['time'] ?? '12:00';
        [$hour, $minute] = explode(':', $time);
        
        $next = $now->setTime((int)$hour, (int)$minute, 0);
        
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        
        return $next;
    }

    private function calculateNextWeekly(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $dayOfWeek = $this->scheduleConfig['day_of_week'] ?? 'monday';
        $time = $this->scheduleConfig['time'] ?? '12:00';
        [$hour, $minute] = explode(':', $time);
        
        $next = $now->modify('next ' . $dayOfWeek)->setTime((int)$hour, (int)$minute, 0);
        
        if ($next <= $now) {
            $next = $next->modify('+1 week');
        }
        
        return $next;
    }

    private function calculateNextBiweekly(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $dayOfWeek = $this->scheduleConfig['day_of_week'] ?? 'monday';
        $time = $this->scheduleConfig['time'] ?? '12:00';
        $startWeek = (int)($this->scheduleConfig['start_week'] ?? $now->format('W'));
        
        [$hour, $minute] = explode(':', $time);
        
        $next = $now->modify('next ' . $dayOfWeek)->setTime((int)$hour, (int)$minute, 0);
        
        // Prüfe ob es eine "gerade" Woche ist (relativ zur Startwoche)
        $currentWeek = (int)$next->format('W');
        $weekDiff = abs($currentWeek - $startWeek);
        
        if ($weekDiff % 2 !== 0) {
            $next = $next->modify('+1 week');
        }
        
        if ($next <= $now) {
            $next = $next->modify('+2 weeks');
        }
        
        return $next;
    }

    private function calculateNextMonthly(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $dayOfMonth = $this->scheduleConfig['day_of_month'] ?? 1;
        $time = $this->scheduleConfig['time'] ?? '12:00';
        [$hour, $minute] = explode(':', $time);
        
        $next = $now->setDate(
            (int)$now->format('Y'),
            (int)$now->format('m'),
            min($dayOfMonth, (int)$now->format('t')) // Verhindere ungültige Tage
        )->setTime((int)$hour, (int)$minute, 0);
        
        if ($next <= $now) {
            $next = $next->modify('+1 month')->setDate(
                (int)$next->format('Y'),
                (int)$next->format('m'),
                min($dayOfMonth, (int)$next->format('t'))
            );
        }
        
        return $next;
    }

    public function isScheduled(): bool
    {
        return $this->isScheduled;
    }

    public function isDueForExecution(): bool
    {
        if (!$this->isScheduled || !$this->nextRunAt) {
            return false;
        }

        if ($this->maxExecutions !== null && $this->executionCount >= $this->maxExecutions) {
            return false;
        }

        return $this->nextRunAt <= new \DateTimeImmutable();
    }

    public function markExecuted(): void
    {
        $this->lastRunAt = new \DateTimeImmutable();
        $this->executionCount++;

        // Bei 'once' Schedule deaktivieren
        if ($this->scheduleType === 'once') {
            $this->isScheduled = false;
            $this->nextRunAt = null;
        } else {
            $this->calculateNextRun();
        }
    }

    // ✅ Approval Methods
    
    public function requireApproval(): self
    {
        $this->requiresApproval = true;
        $this->status = 'draft';
        return $this;
    }

    public function approve(?int $userId = null): self
    {
        $this->approvedAt = new \DateTimeImmutable();
        $this->approvedBy = $userId;
        $this->status = 'approved';
        return $this;
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }
    // ✅ NEU: Getter für requiresApproval
    public function isRequiresApproval(): bool
    {
        return $this->requiresApproval;
    }
    public function canExecute(): bool
    {
        if ($this->requiresApproval && !$this->isApproved()) {
            return false;
        }

        return in_array($this->status, ['approved', 'draft', 'waiting_user_input']);
    }

    // ✅ User Interaction Methods
    
    public function requireUserInteraction(string $message, array $context = []): self
    {
        $this->status = 'waiting_user_input';
        $this->userInteractionMessage = $message;
        $this->userInteractionRequired = $context;
        return $this;
    }

    public function resolveUserInteraction(array $resolution): self
    {
        $this->userInteractionRequired = array_merge(
            $this->userInteractionRequired ?? [],
            ['resolution' => $resolution, 'resolved_at' => (new \DateTimeImmutable())->format('c')]
        );
        $this->status = 'approved';
        return $this;
    }

    public function hasUserInteraction(): bool
    {
        return $this->status === 'waiting_user_input';
    }

    // ✅ Template Methods
    
    public function saveAsTemplate(string $name): self
    {
        $this->isTemplate = true;
        $this->templateName = $name;
        return $this;
    }
    public function isTemplate(): bool
    {
        return $this->isTemplate !== null;
    }
    // Getters & Setters (Standard)
    
    public function getId(): ?int
    {
        return $this->id;
    }
    #[Groups(['workflow:read'])]
    public function getUserId(): ?int
    {
        // Wir gehen davon aus, dass die $user-Eigenschaft immer gesetzt ist (nullable: false)
        return $this->user->getId();
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getScheduleType(): ?string
    {
        return $this->scheduleType;
    }

    public function getScheduleConfig(): ?array
    {
        return $this->scheduleConfig;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function getExecutionCount(): int
    {
        return $this->executionCount;
    }

    public function getUserInteractionMessage(): ?string
    {
        return $this->userInteractionMessage;
    }

    public function getUserInteractionRequired(): ?array
    {
        return $this->userInteractionRequired;
    }

    public function getExecutions(): Collection
    {
        return $this->executions;
    }
}