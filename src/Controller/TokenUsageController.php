<?php
// src/Controller/TokenUsageController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserLlmSettings;
use App\Repository\UserLlmSettingsRepository;
use App\Service\TokenTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tokens', name: 'api_tokens_')]
#[OA\Tag(name: 'Token Usage & Limits')]
class TokenUsageController extends AbstractController
{
    public function __construct(
        private TokenTrackingService $tokenService,
        private UserLlmSettingsRepository $settingsRepo,
        private EntityManagerInterface $em
    ) {}

    /**
     * Holt aktuelle Token-Limits und Nutzung
     */
    #[Route('/limits', name: 'limits_get', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt aktuelle Token-Limits',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token-Limits und aktuelle Nutzung',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'limits', type: 'object'),
                        new OA\Property(property: 'settings', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function getLimits(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $settings = $this->tokenService->getOrCreateSettings($user);

        return $this->json([
            'settings' => [
                'minute_limit' => $settings->getMinuteLimit(),
                'minute_limit_enabled' => $settings->isMinuteLimitEnabled(),
                'hour_limit' => $settings->getHourLimit(),
                'hour_limit_enabled' => $settings->isHourLimitEnabled(),
                'day_limit' => $settings->getDayLimit(),
                'day_limit_enabled' => $settings->isDayLimitEnabled(),
                'week_limit' => $settings->getWeekLimit(),
                'week_limit_enabled' => $settings->isWeekLimitEnabled(),
                'month_limit' => $settings->getMonthLimit(),
                'month_limit_enabled' => $settings->isMonthLimitEnabled(),
                'cost_per_million_tokens' => $settings->getCostPerMillionTokens(),
                'notify_on_limit_reached' => $settings->isNotifyOnLimitReached(),
                'warning_threshold_percent' => $settings->getWarningThresholdPercent()
            ],
            'current_usage' => $this->tokenService->getUsageStatistics($user)['limits']
        ]);
    }

    /**
     * Setzt oder aktualisiert Token-Limits
     */
    #[Route('/limits', name: 'limits_update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Aktualisiert Token-Limits',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'minute_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'minute_limit_enabled', type: 'boolean'),
                    new OA\Property(property: 'hour_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'hour_limit_enabled', type: 'boolean'),
                    new OA\Property(property: 'day_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'day_limit_enabled', type: 'boolean'),
                    new OA\Property(property: 'week_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'week_limit_enabled', type: 'boolean'),
                    new OA\Property(property: 'month_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'month_limit_enabled', type: 'boolean'),
                    new OA\Property(property: 'cost_per_million_tokens', type: 'integer'),
                    new OA\Property(property: 'notify_on_limit_reached', type: 'boolean'),
                    new OA\Property(property: 'warning_threshold_percent', type: 'integer')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Limits aktualisiert'),
            new OA\Response(response: 400, description: 'Ungültige Eingabe')
        ]
    )]
    public function updateLimits(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $settings = $this->tokenService->getOrCreateSettings($user);

        // Update limits
        if (isset($data['minute_limit'])) {
            $settings->setMinuteLimit($data['minute_limit']);
        }
        if (isset($data['minute_limit_enabled'])) {
            $settings->setMinuteLimitEnabled($data['minute_limit_enabled']);
        }
        if (isset($data['hour_limit'])) {
            $settings->setHourLimit($data['hour_limit']);
        }
        if (isset($data['hour_limit_enabled'])) {
            $settings->setHourLimitEnabled($data['hour_limit_enabled']);
        }
        if (isset($data['day_limit'])) {
            $settings->setDayLimit($data['day_limit']);
        }
        if (isset($data['day_limit_enabled'])) {
            $settings->setDayLimitEnabled($data['day_limit_enabled']);
        }
        if (isset($data['week_limit'])) {
            $settings->setWeekLimit($data['week_limit']);
        }
        if (isset($data['week_limit_enabled'])) {
            $settings->setWeekLimitEnabled($data['week_limit_enabled']);
        }
        if (isset($data['month_limit'])) {
            $settings->setMonthLimit($data['month_limit']);
        }
        if (isset($data['month_limit_enabled'])) {
            $settings->setMonthLimitEnabled($data['month_limit_enabled']);
        }
        if (isset($data['cost_per_million_tokens'])) {
            $settings->setCostPerMillionTokens($data['cost_per_million_tokens']);
        }
        if (isset($data['notify_on_limit_reached'])) {
            $settings->setNotifyOnLimitReached($data['notify_on_limit_reached']);
        }
        if (isset($data['warning_threshold_percent'])) {
            $settings->setWarningThresholdPercent($data['warning_threshold_percent']);
        }

        $settings->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'status' => 'success',
            'message' => 'Limits updated successfully',
            'settings' => [
                'minute_limit' => $settings->getMinuteLimit(),
                'hour_limit' => $settings->getHourLimit(),
                'day_limit' => $settings->getDayLimit(),
                'week_limit' => $settings->getWeekLimit(),
                'month_limit' => $settings->getMonthLimit()
            ]
        ]);
    }

    /**
     * Holt Token-Usage-Statistiken
     */
    #[Route('/usage', name: 'usage_get', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt Token-Nutzungsstatistiken',
        parameters: [
            new OA\Parameter(
                name: 'period',
                in: 'query',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['minute', 'hour', 'day', 'week', 'month', 'year']
                )
            ),
            new OA\Parameter(name: 'start_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'end_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token-Usage-Statistiken',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'period', type: 'string'),
                        new OA\Property(property: 'total_tokens', type: 'integer'),
                        new OA\Property(property: 'total_cost_cents', type: 'integer'),
                        new OA\Property(property: 'by_model', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'limits', type: 'object'),
                        new OA\Property(property: 'usage_percent', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function getUsage(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $period = $request->query->get('period');
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');

        $startDateObj = $startDate ? new \DateTimeImmutable($startDate) : null;
        $endDateObj = $endDate ? new \DateTimeImmutable($endDate) : null;

        try {
            $stats = $this->tokenService->getUsageStatistics(
                $user,
                $period,
                $startDateObj,
                $endDateObj
            );

            return $this->json([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch statistics',
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Holt detaillierte Usage-History
     */
    #[Route('/usage/history', name: 'usage_history', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt detaillierte Token-Usage-History',
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', default: 30)),
            new OA\Parameter(name: 'model', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'agent_type', in: 'query', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usage-History')
        ]
    )]
    public function getUsageHistory(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $days = (int) ($request->query->get('days', 30));
        $endDate = new \DateTimeImmutable();
        $startDate = $endDate->modify("-{$days} days");

        $stats = $this->tokenService->getUsageStatistics($user, null, $startDate, $endDate);

        return $this->json([
            'status' => 'success',
            'period' => "{$days} days",
            'start_date' => $startDate->format('c'),
            'end_date' => $endDate->format('c'),
            'data' => $stats
        ]);
    }

    /**
     * Prüft aktuelle Limits
     */
    #[Route('/limits/check', name: 'limits_check', methods: ['GET'])]
    #[OA\Get(
        summary: 'Prüft ob User noch Token-Kapazität hat',
        parameters: [
            new OA\Parameter(
                name: 'estimated_tokens',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 0)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Limit-Check erfolgreich',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'allowed', type: 'boolean'),
                        new OA\Property(property: 'limits', type: 'object')
                    ]
                )
            ),
            new OA\Response(response: 429, description: 'Limit erreicht')
        ]
    )]
    public function checkLimits(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $estimatedTokens = (int) $request->query->get('estimated_tokens', 0);

        try {
            $this->tokenService->checkLimits($user, $estimatedTokens);
            
            $stats = $this->tokenService->getUsageStatistics($user);

            return $this->json([
                'allowed' => true,
                'estimated_tokens' => $estimatedTokens,
                'current_limits' => $stats['limits']
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'allowed' => false,
                'message' => $e->getMessage(),
                'estimated_tokens' => $estimatedTokens
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    /**
     * Setzt Limits zurück (nur für Admin)
     */
    #[Route('/limits/reset', name: 'limits_reset', methods: ['POST'])]
    #[OA\Post(
        summary: 'Setzt Token-Limits auf Standardwerte zurück',
        responses: [
            new OA\Response(response: 200, description: 'Limits zurückgesetzt')
        ]
    )]
    public function resetLimits(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $settings = $this->tokenService->getOrCreateSettings($user);
        
        // Reset to defaults
        $settings->setMinuteLimit(100000);
        $settings->setHourLimit(1000000);
        $settings->setDayLimit(10000000);
        $settings->setWeekLimit(50000000);
        $settings->setMonthLimit(200000000);
        
        $settings->setMinuteLimitEnabled(true);
        $settings->setHourLimitEnabled(true);
        $settings->setDayLimitEnabled(true);
        $settings->setWeekLimitEnabled(false);
        $settings->setMonthLimitEnabled(true);
        
        $settings->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'status' => 'success',
            'message' => 'Limits reset to defaults',
            'settings' => [
                'minute_limit' => $settings->getMinuteLimit(),
                'hour_limit' => $settings->getHourLimit(),
                'day_limit' => $settings->getDayLimit(),
                'week_limit' => $settings->getWeekLimit(),
                'month_limit' => $settings->getMonthLimit()
            ]
        ]);
    }
}