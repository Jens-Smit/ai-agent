<?php
// src/Service/Workflow/Executor/SmartRetryTrait.php

declare(strict_types=1);

namespace App\Service\Workflow\Executor;

use App\Entity\WorkflowStep;
use Psr\Log\LoggerInterface;

/**
 * Smart Retry Logic für Job-Suche und andere Tools
 */
trait SmartRetryTrait
{
    /**
     * Prüft ob ein Step übersprungen werden soll (bei Retry-Logik)
     * 
     * @return bool True wenn Step übersprungen werden soll
     */
    private function shouldSkipStep(WorkflowStep $step, array $context): bool
    {
        // Prüfe vorherigen Decision-Step
        $prevStepNumber = $step->getStepNumber() - 1;
        $prevStepKey = 'step_' . $prevStepNumber;
        
        if (!isset($context[$prevStepKey]['result'])) {
            return false;
        }
        
        $prevResult = $context[$prevStepKey]['result'];
        
        // Wenn vorheriger Decision sagt "has_results=true", überspringe weitere Retry-Versuche
        if (isset($prevResult['has_results']) && $prevResult['has_results'] === true) {
            $this->logger->info('Skipping retry step - previous attempt was successful', [
                'step' => $step->getStepNumber(),
                'prev_step' => $prevStepNumber
            ]);
            
            // Kopiere Ergebnis vom erfolgreichen Versuch
            return true;
        }
        
        // Wenn vorheriger Decision sagt "retry_needed=false", überspringe
        if (isset($prevResult['retry_needed']) && $prevResult['retry_needed'] === false) {
            $this->logger->info('Skipping retry step - retry not needed', [
                'step' => $step->getStepNumber()
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Kopiert Ergebnis vom letzten erfolgreichen Job-Such-Versuch
     */
    private function copyLastSuccessfulJobResult(WorkflowStep $step, array $context): array
    {
        // Suche rückwärts nach letztem erfolgreichen job_search
        for ($i = $step->getStepNumber() - 1; $i > 0; $i--) {
            $stepKey = 'step_' . $i;
            if (!isset($context[$stepKey]['result'])) {
                continue;
            }
            
            $result = $context[$stepKey]['result'];
            
            // Prüfe ob es ein erfolgreicher Decision-Step war
            if (isset($result['has_results']) && $result['has_results'] === true) {
                $this->logger->info('Copying result from successful attempt', [
                    'current_step' => $step->getStepNumber(),
                    'source_step' => $i
                ]);
                
                return $result;
            }
        }
        
        // Fallback: Leeres Ergebnis (sollte nicht vorkommen)
        return [
            'has_results' => false,
            'job_count' => 0,
            'skipped' => true
        ];
    }
    
    /**
     * Erkennt ob ein Step Teil einer Retry-Logik ist
     */
    private function isRetryStep(WorkflowStep $step): bool
    {
        $description = strtolower($step->getDescription());
        
        $retryKeywords = [
            'versuch 2',
            'versuch 3',
            'versuch 4',
            'versuch 5',
            'retry',
            'erweitertem radius',
            'alternativer berufsbezeichnung',
            'nur wenn',
            'falls leer',
            'falls versuch'
        ];
        
        foreach ($retryKeywords as $keyword) {
            if (str_contains($description, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Findet den besten Job aus allen Retry-Versuchen
     */
    private function findBestJobFromRetries(array $context, int $currentStep): array
    {
        $allJobs = [];
        
        // Sammle alle Job-Ergebnisse aus Decision-Steps
        for ($i = 1; $i < $currentStep; $i++) {
            $stepKey = 'step_' . $i;
            if (!isset($context[$stepKey]['result'])) {
                continue;
            }
            
            $result = $context[$stepKey]['result'];
            
            // Prüfe ob es ein Decision-Step mit Jobs war
            if (isset($result['has_results']) && 
                $result['has_results'] === true && 
                isset($result['job_count']) && 
                $result['job_count'] > 0) {
                
                $allJobs[] = [
                    'step' => $i,
                    'company' => $result['best_company'] ?? $result['best_job_company'] ?? '',
                    'title' => $result['best_job_title'] ?? $result['final_job_title'] ?? '',
                    'url' => $result['best_job_url'] ?? $result['final_job_url'] ?? '',
                    'job_count' => $result['job_count']
                ];
            }
        }
        
        if (empty($allJobs)) {
            return [
                'has_results' => false,
                'final_job_title' => '',
                'final_company' => '',
                'final_job_url' => '',
                'source_attempt' => 'none'
            ];
        }
        
        // Wähle Job mit meisten Ergebnissen (= beste Übereinstimmung)
        usort($allJobs, fn($a, $b) => $b['job_count'] <=> $a['job_count']);
        $bestJob = $allJobs[0];
        
        $this->logger->info('Selected best job from retry attempts', [
            'total_attempts' => count($allJobs),
            'selected_from_step' => $bestJob['step'],
            'company' => $bestJob['company']
        ]);
        
        return [
            'has_results' => true,
            'final_job_title' => $bestJob['title'],
            'final_company' => $bestJob['company'],
            'final_job_url' => $bestJob['url'],
            'source_attempt' => 'step_' . $bestJob['step']
        ];
    }
}