<?php
// src/Service/Workflow/Strategy/SmartJobSearchStrategy.php

declare(strict_types=1);

namespace App\Service\Workflow\Strategy;

use Psr\Log\LoggerInterface;

/**
 * Intelligente Job-Such-Strategie mit automatischen Fallbacks
 * 
 * Features:
 * - Erweitert Suchradius automatisch (10km → 20km → 50km)
 * - Findet alternative Jobtitel (Geschäftsführer → Niederlassungsleiter → Betriebsleiter)
 * - Skill-basierte Suche wenn keine direkten Matches
 * - Kombiniert mehrere Strategien
 */
final class SmartJobSearchStrategy
{
    // Job-Titel-Hierarchie für Fallbacks
    private const JOB_TITLE_FALLBACKS = [
        'geschäftsführer' => [
            'Geschäftsführer',
            'Niederlassungsleiter',
            'Betriebsleiter',
            'Filialleiter',
            'Operations Manager',
            'General Manager'
        ],
        'softwareentwickler' => [
            'Softwareentwickler',
            'Software Engineer',
            'Developer',
            'Programmierer',
            'Full Stack Developer',
            'Backend Developer'
        ],
        'projektmanager' => [
            'Projektmanager',
            'Project Manager',
            'Projektleiter',
            'Program Manager',
            'Scrum Master'
        ]
    ];

    // Radius-Eskalation in km
    private const RADIUS_STEPS = [0, 10, 20, 50, 100];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Generiert dynamische Search-Varianten basierend auf Context
     */
    public function generateSearchVariants(array $extractedParams): array
    {
        $baseTitle = strtolower(trim($extractedParams['job_title'] ?? ''));
        $baseLocation = trim($extractedParams['job_location'] ?? '');
        $skills = $this->parseSkills($extractedParams['skills'] ?? '');

        if (empty($baseTitle) && empty($skills)) {
            throw new \RuntimeException('Keine Suchparameter verfügbar (weder Jobtitel noch Skills)');
        }

        $variants = [];

        // Strategie 1: Jobtitel + Location mit Radius-Eskalation
        if (!empty($baseTitle) && !empty($baseLocation)) {
            $titleVariants = $this->getTitleFallbacks($baseTitle);
            
            foreach ($titleVariants as $titleIndex => $title) {
                foreach (self::RADIUS_STEPS as $radiusIndex => $radius) {
                    $variants[] = [
                        'strategy' => 'title_location_radius',
                        'priority' => ($titleIndex * 10) + $radiusIndex, // 0=höchste Priorität
                        'what' => $title,
                        'where' => $baseLocation,
                        'radius' => $radius,
                        'description' => sprintf(
                            '%s in %s%s',
                            $title,
                            $baseLocation,
                            $radius > 0 ? " (+{$radius}km)" : ''
                        )
                    ];
                }
            }
        }

        // Strategie 2: Nur Jobtitel (bundesweit) wenn Location fehlt
        if (!empty($baseTitle) && empty($baseLocation)) {
            $titleVariants = $this->getTitleFallbacks($baseTitle);
            
            foreach ($titleVariants as $index => $title) {
                $variants[] = [
                    'strategy' => 'title_nationwide',
                    'priority' => 50 + $index,
                    'what' => $title,
                    'where' => 'Deutschland',
                    'radius' => 0,
                    'description' => sprintf('%s (bundesweit)', $title)
                ];
            }
        }

        // Strategie 3: Skill-basierte Suche (wenn Jobtitel fehlt oder als letzter Fallback)
        if (!empty($skills)) {
            foreach ($skills as $index => $skill) {
                $variants[] = [
                    'strategy' => 'skill_based',
                    'priority' => 100 + $index,
                    'what' => $skill,
                    'where' => $baseLocation ?: 'Deutschland',
                    'radius' => 0,
                    'description' => sprintf('Skill: %s', $skill)
                ];
            }
        }

        // Strategie 4: Branche aus Skills ableiten
        $industries = $this->extractIndustries($skills);
        foreach ($industries as $index => $industry) {
            $variants[] = [
                'strategy' => 'industry_based',
                'priority' => 200 + $index,
                'what' => $industry,
                'where' => $baseLocation ?: 'Deutschland',
                'radius' => 0,
                'description' => sprintf('Branche: %s', $industry)
            ];
        }

        // Sortiere nach Priorität (0 = beste Chance)
        usort($variants, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $this->logger->info('Generated search variants', [
            'total_variants' => count($variants),
            'base_title' => $baseTitle,
            'base_location' => $baseLocation,
            'skills_count' => count($skills)
        ]);

        return $variants;
    }

    /**
     * Findet Title-Fallbacks für einen Jobtitel
     */
    private function getTitleFallbacks(string $baseTitle): array
    {
        $normalized = strtolower(trim($baseTitle));

        // Exakter Match in Fallback-Map
        if (isset(self::JOB_TITLE_FALLBACKS[$normalized])) {
            return self::JOB_TITLE_FALLBACKS[$normalized];
        }

        // Partial Match (z.B. "senior geschäftsführer" → "geschäftsführer" fallbacks)
        foreach (self::JOB_TITLE_FALLBACKS as $key => $fallbacks) {
            if (str_contains($normalized, $key)) {
                return array_merge([$baseTitle], $fallbacks);
            }
        }

        // Keine Fallbacks gefunden: Nur Original-Titel
        return [$baseTitle];
    }

    /**
     * Parsed Skills aus Komma-separiertem String oder Array
     */
    private function parseSkills(mixed $skills): array
    {
        if (is_array($skills)) {
            return array_filter(array_map('trim', $skills));
        }

        if (is_string($skills)) {
            $parsed = array_filter(array_map('trim', explode(',', $skills)));
            // Limitiere auf relevanteste Skills (max 5)
            return array_slice($parsed, 0, 5);
        }

        return [];
    }

    /**
     * Extrahiert Branchen aus Skills
     */
    private function extractIndustries(array $skills): array
    {
        $industries = [];
        $skillToIndustry = [
            'php' => 'Webentwicklung',
            'symfony' => 'Webentwicklung',
            'javascript' => 'Webentwicklung',
            'react' => 'Webentwicklung',
            'python' => 'Softwareentwicklung',
            'personalführung' => 'Management',
            'kundenberatung' => 'Vertrieb',
            'marketing' => 'Marketing'
        ];

        foreach ($skills as $skill) {
            $normalized = strtolower(trim($skill));
            foreach ($skillToIndustry as $keyword => $industry) {
                if (str_contains($normalized, $keyword)) {
                    $industries[$industry] = true;
                }
            }
        }

        return array_keys($industries);
    }

    /**
     * Evaluiert ob ein Job-Search-Result gut genug ist
     */
    public function evaluateSearchResult(array $result, array $searchParams): array
    {
        $jobCount = $result['job_count'] ?? 0;
        $hasResults = $jobCount > 0;

        // Quality Score (0-100)
        $qualityScore = 0;

        if ($hasResults) {
            // Basis-Score nach Anzahl Jobs
            $qualityScore = min(100, $jobCount * 10);

            // Bonus für exakten Match mit Original-Parametern
            if ($searchParams['strategy'] === 'title_location_radius' && 
                $searchParams['radius'] === 0) {
                $qualityScore += 20;
            }

            // Malus für weit entfernte Fallbacks
            if ($searchParams['priority'] > 50) {
                $qualityScore = max(0, $qualityScore - 30);
            }
        }

        return [
            'has_results' => $hasResults,
            'job_count' => $jobCount,
            'quality_score' => $qualityScore,
            'is_acceptable' => $qualityScore >= 30, // Mindest-Qualität
            'strategy_used' => $searchParams['strategy'],
            'search_description' => $searchParams['description'],
            'should_retry' => !$hasResults || $qualityScore < 30
        ];
    }
}