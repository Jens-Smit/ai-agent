<?php
// src/Service/Workflow/Executor/SmartDecisionTrait.php

declare(strict_types=1);

namespace App\Service\Workflow\Executor;

use App\Entity\WorkflowStep;
use App\Service\Workflow\Strategy\SmartJobSearchStrategy;

/**
 * Intelligente Decision-Logik mit dynamischen Retry-Strategien
 */
trait SmartDecisionTrait
{
    private ?SmartJobSearchStrategy $searchStrategy = null;

    /**
     * Führt einen Decision-Step mit Smart Retry Logic aus
     */
    private function executeSmartDecision(
        WorkflowStep $step,
        array $context,
        string $sessionId
    ): array {
        $description = strtolower($step->getDescription());

        // Erkenne Decision-Type
        if (str_contains($description, 'job') && 
            (str_contains($description, 'suche') || str_contains($description, 'ergebnis'))) {
            return $this->executeJobSearchDecision($step, $context, $sessionId);
        }

        // Fallback: Standard Decision
        return $this->executeDecision($step, $context, $sessionId);
    }

    /**
     * Job-Search Decision mit automatischen Fallbacks
     */
    private function executeJobSearchDecision(
        WorkflowStep $step,
        array $context,
        string $sessionId
    ): array {
        if (!$this->searchStrategy) {
            $this->searchStrategy = new SmartJobSearchStrategy($this->logger);
        }

        // Finde letzten Job-Search-Versuch
        $lastSearchResult = $this->findLastJobSearchResult($context, $step->getStepNumber());
        
        if (!$lastSearchResult) {
            $this->logger->warning('No previous job search found in context', [
                'step' => $step->getStepNumber()
            ]);
            
            return [
                'has_results' => false,
                'should_retry' => true,
                'retry_reason' => 'no_previous_search',
                'next_action' => 'start_new_search'
            ];
        }

        // Evaluiere Suchergebnis
        $searchParams = $lastSearchResult['search_params'] ?? [];
        $evaluation = $this->searchStrategy->evaluateSearchResult(
            $lastSearchResult['result'] ?? [],
            $searchParams
        );

        $this->logger->info('Job search evaluation', [
            'step' => $step->getStepNumber(),
            'evaluation' => $evaluation
        ]);

        // Wenn Ergebnis gut genug: Übernehme es
        if ($evaluation['is_acceptable']) {
            $this->statusService->addStatus(
                $sessionId,
                sprintf(
                    '✅ Jobs gefunden: %d Ergebnisse (%s)',
                    $evaluation['job_count'],
                    $evaluation['search_description']
                )
            );

            return [
                'has_results' => true,
                'job_count' => $evaluation['job_count'],
                'quality_score' => $evaluation['quality_score'],
                'strategy_used' => $evaluation['strategy_used'],
                'best_job_title' => $lastSearchResult['result']['jobs'][0]['title'] ?? '',
                'best_job_company' => $lastSearchResult['result']['jobs'][0]['company'] ?? '',
                'best_job_url' => $lastSearchResult['result']['jobs'][0]['url'] ?? '',
                'should_retry' => false
            ];
        }

        // Wenn nicht gut genug: Plane nächsten Versuch
        $nextVariant = $this->getNextSearchVariant($context, $step->getStepNumber());

        if ($nextVariant) {
            $this->statusService->addStatus(
                $sessionId,
                sprintf(
                    '🔄 Keine guten Ergebnisse - versuche: %s',
                    $nextVariant['description']
                )
            );

            return [
                'has_results' => false,
                'should_retry' => true,
                'next_search_params' => $nextVariant,
                'retry_reason' => 'quality_too_low',
                'previous_quality_score' => $evaluation['quality_score']
            ];
        }

        // Alle Varianten versucht: Nimm beste
        $bestAttempt = $this->findBestJobSearchAttempt($context, $step->getStepNumber());

        $this->statusService->addStatus(
            $sessionId,
            sprintf(
                '⚠️ Alle Suchvarianten versucht - nutze beste Ergebnis (%d Jobs)',
                $bestAttempt['job_count'] ?? 0
            )
        );

        return [
            'has_results' => ($bestAttempt['job_count'] ?? 0) > 0,
            'job_count' => $bestAttempt['job_count'] ?? 0,
            'best_job_title' => $bestAttempt['best_job_title'] ?? '',
            'best_job_company' => $bestAttempt['best_job_company'] ?? '',
            'best_job_url' => $bestAttempt['best_job_url'] ?? '',
            'should_retry' => false,
            'all_attempts_exhausted' => true
        ];
    }

    /**
     * Findet letztes Job-Search-Result im Context
     */
    private function findLastJobSearchResult(array $context, int $currentStep): ?array
    {
        // Suche rückwärts nach job_search Tool-Call
        for ($i = $currentStep - 1; $i > 0; $i--) {
            $stepKey = 'step_' . $i;
            
            if (!isset($context[$stepKey]['result'])) {
                continue;
            }

            $result = $context[$stepKey]['result'];

            // Prüfe ob es ein job_search Result ist
            if (isset($result['tool']) && $result['tool'] === 'job_search') {
                return [
                    'step' => $i,
                    'result' => $result,
                    'search_params' => $context['search_variants'][$i] ?? []
                ];
            }

            // Oder direktes job_search Result
            if (isset($result['jobs']) || isset($result['job_count'])) {
                return [
                    'step' => $i,
                    'result' => $result,
                    'search_params' => $context['search_variants'][$i] ?? []
                ];
            }
        }

        return null;
    }

    /**
     * Holt nächste Search-Variante aus vorgenerierten Varianten
     */
    private function getNextSearchVariant(array $context, int $currentStep): ?array
    {
        if (!isset($context['search_variants_list'])) {
            $this->logger->warning('No search_variants_list in context');
            return null;
        }

        $allVariants = $context['search_variants_list'];
        $attemptedCount = $this->countJobSearchAttempts($context, $currentStep);

        if ($attemptedCount >= count($allVariants)) {
            return null; // Alle Varianten versucht
        }

        return $allVariants[$attemptedCount] ?? null;
    }

    /**
     * Zählt wie viele Job-Search-Versuche bereits gemacht wurden
     */
    private function countJobSearchAttempts(array $context, int $currentStep): int
    {
        $count = 0;

        for ($i = 1; $i < $currentStep; $i++) {
            $stepKey = 'step_' . $i;
            
            if (!isset($context[$stepKey]['result'])) {
                continue;
            }

            $result = $context[$stepKey]['result'];

            if (isset($result['tool']) && $result['tool'] === 'job_search') {
                $count++;
            } elseif (isset($result['jobs']) || isset($result['job_count'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Findet besten Job-Search-Versuch aus allen Versuchen
     */
    private function findBestJobSearchAttempt(array $context, int $currentStep): array
    {
        $bestAttempt = [
            'job_count' => 0,
            'quality_score' => 0
        ];

        for ($i = 1; $i < $currentStep; $i++) {
            $stepKey = 'step_' . $i;
            
            if (!isset($context[$stepKey]['result'])) {
                continue;
            }

            $result = $context[$stepKey]['result'];

            // Extrahiere Job-Count
            $jobCount = 0;
            if (isset($result['job_count'])) {
                $jobCount = $result['job_count'];
            } elseif (isset($result['jobs']) && is_array($result['jobs'])) {
                $jobCount = count($result['jobs']);
            }

            if ($jobCount > $bestAttempt['job_count']) {
                $bestAttempt = [
                    'step' => $i,
                    'job_count' => $jobCount,
                    'best_job_title' => $result['jobs'][0]['title'] ?? '',
                    'best_job_company' => $result['jobs'][0]['company'] ?? '',
                    'best_job_url' => $result['jobs'][0]['url'] ?? '',
                    'quality_score' => $jobCount * 10
                ];
            }
        }

        return $bestAttempt;
    }

    /**
     * Generiert Search-Varianten basierend auf extrahierten Parametern
     */
    private function generateAndStoreSearchVariants(array $extractedParams, array &$context): void
    {
        if (!$this->searchStrategy) {
            $this->searchStrategy = new SmartJobSearchStrategy($this->logger);
        }

        $variants = $this->searchStrategy->generateSearchVariants($extractedParams);

        // Speichere Varianten im Context für spätere Steps
        $context['search_variants_list'] = $variants;
        $context['search_variants_count'] = count($variants);

        $this->logger->info('Generated and stored search variants', [
            'count' => count($variants),
            'first_variant' => $variants[0] ?? null
        ]);
    }
}