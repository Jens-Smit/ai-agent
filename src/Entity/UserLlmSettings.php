<?php
// src/Entity/UserLlmSettings.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserLlmSettingsRepository;

#[ORM\Entity(repositoryClass: UserLlmSettingsRepository::class)]
#[ORM\Table(name: 'user_llm_settings')]
class UserLlmSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // Token Limits
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $minuteLimit = 100000; // 100K tokens/minute

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $hourLimit = 1000000; // 1M tokens/hour

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $dayLimit = 10000000; // 10M tokens/day

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $weekLimit = 50000000; // 50M tokens/week

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $monthLimit = 200000000; // 200M tokens/month

    // Enable/Disable Limits
    #[ORM\Column(type: 'boolean')]
    private bool $minuteLimitEnabled = true;

    #[ORM\Column(type: 'boolean')]
    private bool $hourLimitEnabled = true;

    #[ORM\Column(type: 'boolean')]
    private bool $dayLimitEnabled = true;

    #[ORM\Column(type: 'boolean')]
    private bool $weekLimitEnabled = false;

    #[ORM\Column(type: 'boolean')]
    private bool $monthLimitEnabled = true;

    // Cost per 1M tokens (in cents)
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $costPerMillionTokens = 1500; // $15 per 1M tokens

    // Notifications
    #[ORM\Column(type: 'boolean')]
    private bool $notifyOnLimitReached = true;

    #[ORM\Column(type: 'integer')]
    private int $warningThresholdPercent = 80; // Warn at 80% usage

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getMinuteLimit(): ?int
    {
        return $this->minuteLimit;
    }

    public function setMinuteLimit(?int $minuteLimit): self
    {
        $this->minuteLimit = $minuteLimit;
        return $this;
    }

    public function getHourLimit(): ?int
    {
        return $this->hourLimit;
    }

    public function setHourLimit(?int $hourLimit): self
    {
        $this->hourLimit = $hourLimit;
        return $this;
    }

    public function getDayLimit(): ?int
    {
        return $this->dayLimit;
    }

    public function setDayLimit(?int $dayLimit): self
    {
        $this->dayLimit = $dayLimit;
        return $this;
    }

    public function getWeekLimit(): ?int
    {
        return $this->weekLimit;
    }

    public function setWeekLimit(?int $weekLimit): self
    {
        $this->weekLimit = $weekLimit;
        return $this;
    }

    public function getMonthLimit(): ?int
    {
        return $this->monthLimit;
    }

    public function setMonthLimit(?int $monthLimit): self
    {
        $this->monthLimit = $monthLimit;
        return $this;
    }

    public function isMinuteLimitEnabled(): bool
    {
        return $this->minuteLimitEnabled;
    }

    public function setMinuteLimitEnabled(bool $enabled): self
    {
        $this->minuteLimitEnabled = $enabled;
        return $this;
    }

    public function isHourLimitEnabled(): bool
    {
        return $this->hourLimitEnabled;
    }

    public function setHourLimitEnabled(bool $enabled): self
    {
        $this->hourLimitEnabled = $enabled;
        return $this;
    }

    public function isDayLimitEnabled(): bool
    {
        return $this->dayLimitEnabled;
    }

    public function setDayLimitEnabled(bool $enabled): self
    {
        $this->dayLimitEnabled = $enabled;
        return $this;
    }

    public function isWeekLimitEnabled(): bool
    {
        return $this->weekLimitEnabled;
    }

    public function setWeekLimitEnabled(bool $enabled): self
    {
        $this->weekLimitEnabled = $enabled;
        return $this;
    }

    public function isMonthLimitEnabled(): bool
    {
        return $this->monthLimitEnabled;
    }

    public function setMonthLimitEnabled(bool $enabled): self
    {
        $this->monthLimitEnabled = $enabled;
        return $this;
    }

    public function getCostPerMillionTokens(): ?int
    {
        return $this->costPerMillionTokens;
    }

    public function setCostPerMillionTokens(?int $cost): self
    {
        $this->costPerMillionTokens = $cost;
        return $this;
    }

    public function isNotifyOnLimitReached(): bool
    {
        return $this->notifyOnLimitReached;
    }

    public function setNotifyOnLimitReached(bool $notify): self
    {
        $this->notifyOnLimitReached = $notify;
        return $this;
    }

    public function getWarningThresholdPercent(): int
    {
        return $this->warningThresholdPercent;
    }

    public function setWarningThresholdPercent(int $percent): self
    {
        $this->warningThresholdPercent = max(0, min(100, $percent));
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}