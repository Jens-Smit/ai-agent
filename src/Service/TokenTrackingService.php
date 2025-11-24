<?php
// src/Service/TokenTrackingService.php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TokenUsage;
use App\Entity\User;
use App\Entity\UserLlmSettings;
use App\Repository\TokenUsageRepository;
use App\Repository\UserLlmSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TokenTrackingService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TokenUsageRepository $tokenUsageRepo,
        private UserLlmSettingsRepository $settingsRepo,
        private LoggerInterface $logger
    ) {}

    /**
     * Trackt Token-Nutzung für einen User
     */
    public function trackTokenUsage(
        User $user,
        string $model,
        string $agentType,
        int $inputTokens,
        int $outputTokens,
        ?string $sessionId = null,
        ?string $requestPreview = null,
        ?int $responseTimeMs = null,
        bool $success = true,
        ?string $errorMessage = null
    ): TokenUsage {
        $usage = new TokenUsage();
        $usage->setUser($user);
        $usage->setModel($model);
        $usage->setAgentType($agentType);
        $usage->setInputTokens($inputTokens);
        $usage->setOutputTokens($outputTokens);
        $usage->setSessionId($sessionId);
        $usage->setRequestPreview($requestPreview);
        $usage->setResponseTimeMs($responseTimeMs);
        $usage->setSuccess($success);
        $usage->setErrorMessage($errorMessage);

        // Calculate cost
        $settings = $this->getOrCreateSettings($user);
        $costCents = $this->calculateCost($usage->getTotalTokens(), $settings);
        $usage->setCostCents($costCents);

        $this->em->persist($usage);
        $this->em->flush();

        $this->logger->info('Token usage tracked', [
            'userId' => $user->getId(),
            'model' => $model,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'totalTokens' => $usage->getTotalTokens(),
            'costCents' => $costCents
        ]);

        return $usage;
    }

    /**
     * Prüft ob User noch Tokens verfügbar hat
     * 
     * @throws \RuntimeException wenn Limit erreicht
     */
    public function checkLimits(User $user, int $estimatedTokens = 0): void
    {
        $settings = $this->getOrCreateSettings($user);
        $now = new \DateTimeImmutable();

        // Minute Limit
        if ($settings->isMinuteLimitEnabled() && $settings->getMinuteLimit()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 minute'),
                $now
            );
            
            if (($usage + $estimatedTokens) > $settings->getMinuteLimit()) {
                throw new \RuntimeException(sprintf(
                    'Minuten-Limit erreicht: %d/%d Tokens',
                    $usage,
                    $settings->getMinuteLimit()
                ));
            }
        }

        // Hour Limit
        if ($settings->isHourLimitEnabled() && $settings->getHourLimit()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 hour'),
                $now
            );
            
            if (($usage + $estimatedTokens) > $settings->getHourLimit()) {
                throw new \RuntimeException(sprintf(
                    'Stunden-Limit erreicht: %d/%d Tokens',
                    $usage,
                    $settings->getHourLimit()
                ));
            }
        }

        // Day Limit
        if ($settings->isDayLimitEnabled() && $settings->getDayLimit()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 day'),
                $now
            );
            
            if (($usage + $estimatedTokens) > $settings->getDayLimit()) {
                throw new \RuntimeException(sprintf(
                    'Tages-Limit erreicht: %d/%d Tokens',
                    $usage,
                    $settings->getDayLimit()
                ));
            }
        }

        // Week Limit
        if ($settings->isWeekLimitEnabled() && $settings->getWeekLimit()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 week'),
                $now
            );
            
            if (($usage + $estimatedTokens) > $settings->getWeekLimit()) {
                throw new \RuntimeException(sprintf(
                    'Wochen-Limit erreicht: %d/%d Tokens',
                    $usage,
                    $settings->getWeekLimit()
                ));
            }
        }

        // Month Limit
        if ($settings->isMonthLimitEnabled() && $settings->getMonthLimit()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 month'),
                $now
            );
            
            if (($usage + $estimatedTokens) > $settings->getMonthLimit()) {
                throw new \RuntimeException(sprintf(
                    'Monats-Limit erreicht: %d/%d Tokens',
                    $usage,
                    $settings->getMonthLimit()
                ));
            }
        }
    }

    /**
     * Holt Usage-Statistiken für verschiedene Zeiträume
     */
    public function getUsageStatistics(
        User $user,
        ?string $period = null,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null
    ): array {
        $now = new \DateTimeImmutable();
        
        if ($period) {
            [$startDate, $endDate] = match($period) {
                'minute' => [$now->modify('-1 minute'), $now],
                'hour' => [$now->modify('-1 hour'), $now],
                'day' => [$now->modify('-1 day'), $now],
                'week' => [$now->modify('-1 week'), $now],
                'month' => [$now->modify('-1 month'), $now],
                'year' => [$now->modify('-1 year'), $now],
                default => throw new \InvalidArgumentException("Invalid period: $period")
            };
        }

        $totalTokens = $this->tokenUsageRepo->getUsageInTimeframe($user, $startDate, $endDate);
        $breakdown = $this->tokenUsageRepo->getUsageBreakdown($user, $startDate, $endDate);
        $settings = $this->getOrCreateSettings($user);

        $totalCost = array_sum(array_column($breakdown, 'cost_cents'));

        return [
            'period' => $period,
            'start_date' => $startDate?->format('c'),
            'end_date' => $endDate?->format('c'),
            'total_tokens' => $totalTokens,
            'total_input_tokens' => array_sum(array_column($breakdown, 'input_tokens')),
            'total_output_tokens' => array_sum(array_column($breakdown, 'output_tokens')),
            'total_cost_cents' => $totalCost,
            'total_cost_dollars' => round($totalCost / 100, 2),
            'by_model' => $breakdown,
            'limits' => $this->getCurrentLimits($user),
            'usage_percent' => $this->calculateUsagePercentages($user, $totalTokens)
        ];
    }

    /**
     * Berechnet aktuelle Limits mit Nutzung
     */
    private function getCurrentLimits(User $user): array
    {
        $settings = $this->getOrCreateSettings($user);
        $now = new \DateTimeImmutable();

        $limits = [];

        if ($settings->isMinuteLimitEnabled()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 minute'),
                $now
            );
            $limits['minute'] = [
                'limit' => $settings->getMinuteLimit(),
                'used' => $usage,
                'remaining' => max(0, $settings->getMinuteLimit() - $usage),
                'percent' => $settings->getMinuteLimit() > 0 
                    ? round(($usage / $settings->getMinuteLimit()) * 100, 2) 
                    : 0
            ];
        }

        if ($settings->isHourLimitEnabled()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 hour'),
                $now
            );
            $limits['hour'] = [
                'limit' => $settings->getHourLimit(),
                'used' => $usage,
                'remaining' => max(0, $settings->getHourLimit() - $usage),
                'percent' => $settings->getHourLimit() > 0 
                    ? round(($usage / $settings->getHourLimit()) * 100, 2) 
                    : 0
            ];
        }

        if ($settings->isDayLimitEnabled()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 day'),
                $now
            );
            $limits['day'] = [
                'limit' => $settings->getDayLimit(),
                'used' => $usage,
                'remaining' => max(0, $settings->getDayLimit() - $usage),
                'percent' => $settings->getDayLimit() > 0 
                    ? round(($usage / $settings->getDayLimit()) * 100, 2) 
                    : 0
            ];
        }

        if ($settings->isWeekLimitEnabled()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 week'),
                $now
            );
            $limits['week'] = [
                'limit' => $settings->getWeekLimit(),
                'used' => $usage,
                'remaining' => max(0, $settings->getWeekLimit() - $usage),
                'percent' => $settings->getWeekLimit() > 0 
                    ? round(($usage / $settings->getWeekLimit()) * 100, 2) 
                    : 0
            ];
        }

        if ($settings->isMonthLimitEnabled()) {
            $usage = $this->tokenUsageRepo->getUsageInTimeframe(
                $user,
                $now->modify('-1 month'),
                $now
            );
            $limits['month'] = [
                'limit' => $settings->getMonthLimit(),
                'used' => $usage,
                'remaining' => max(0, $settings->getMonthLimit() - $usage),
                'percent' => $settings->getMonthLimit() > 0 
                    ? round(($usage / $settings->getMonthLimit()) * 100, 2) 
                    : 0
            ];
        }

        return $limits;
    }

    /**
     * Berechnet Nutzungs-Prozentsätze
     */
    private function calculateUsagePercentages(User $user, int $totalTokens): array
    {
        $settings = $this->getOrCreateSettings($user);
        $percentages = [];

        if ($settings->getMinuteLimit()) {
            $percentages['minute'] = round(($totalTokens / $settings->getMinuteLimit()) * 100, 2);
        }
        if ($settings->getHourLimit()) {
            $percentages['hour'] = round(($totalTokens / $settings->getHourLimit()) * 100, 2);
        }
        if ($settings->getDayLimit()) {
            $percentages['day'] = round(($totalTokens / $settings->getDayLimit()) * 100, 2);
        }
        if ($settings->getWeekLimit()) {
            $percentages['week'] = round(($totalTokens / $settings->getWeekLimit()) * 100, 2);
        }
        if ($settings->getMonthLimit()) {
            $percentages['month'] = round(($totalTokens / $settings->getMonthLimit()) * 100, 2);
        }

        return $percentages;
    }

    /**
     * Berechnet Kosten basierend auf Token-Count
     */
    private function calculateCost(int $tokens, UserLlmSettings $settings): int
    {
        if (!$settings->getCostPerMillionTokens()) {
            return 0;
        }

        // Cost in cents
        return (int) round(($tokens / 1_000_000) * $settings->getCostPerMillionTokens());
    }

    /**
     * Holt oder erstellt LLM-Settings für User
     */
    public function getOrCreateSettings(User $user): UserLlmSettings
    {
        $settings = $this->settingsRepo->findOneBy(['user' => $user]);
        
        if (!$settings) {
            $settings = new UserLlmSettings();
            $settings->setUser($user);
            $this->em->persist($settings);
            $this->em->flush();
        }

        return $settings;
    }
}