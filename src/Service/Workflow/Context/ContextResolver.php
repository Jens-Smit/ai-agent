<?php
// src/Service/Workflow/Context/ContextResolver.php

declare(strict_types=1);

namespace App\Service\Workflow\Context;

use Psr\Log\LoggerInterface;

/**
 * Verbesserter Context-Resolver mit Deep-Nesting-Support
 * 
 * Features:
 * - Löst verschachtelte Pfade auf (step_5.result.jobs[0].company)
 * - Array-Index-Zugriff ([0], [1], etc.)
 * - Fallback-Chain (||)
 * - Default-Values
 * - Automatische Type-Conversion
 */
final class ContextResolver
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Löst ALLE Platzhalter in Daten auf
     */
    public function resolveAll(mixed $data, array $context): mixed
    {
        if (is_string($data)) {
            return $this->resolveString($data, $context);
        }

        if (is_array($data)) {
            $resolved = [];
            foreach ($data as $key => $value) {
                $resolved[$key] = $this->resolveAll($value, $context);
            }
            return $resolved;
        }

        return $data;
    }

    /**
     * Löst Platzhalter in einem String auf
     */
    private function resolveString(string $str, array $context): string
    {
        // Pattern: {{path.to.value||fallback||default}}
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function ($matches) use ($context) {
                return $this->resolvePlaceholder($matches[1], $context);
            },
            $str
        );
    }

    /**
     * Löst einen einzelnen Platzhalter auf
     */
    private function resolvePlaceholder(string $placeholder, array $context): string
    {
        $placeholder = trim($placeholder);

        // Fallback-Chain: {{step_1.result||step_2.result||"default"}}
        if (str_contains($placeholder, '||')) {
            return $this->resolveFallbackChain($placeholder, $context);
        }

        // Single Path
        $value = $this->resolvePathWithArrays($placeholder, $context);

        if ($value !== null) {
            $this->logger->debug('Resolved placeholder', [
                'placeholder' => $placeholder,
                'value_preview' => is_scalar($value) ? $value : gettype($value)
            ]);
            return $this->convertToString($value);
        }

        $this->logger->warning('Placeholder not resolved', [
            'placeholder' => $placeholder,
            'available_keys' => array_keys($context)
        ]);

        // Return original wenn nicht aufgelöst
        return '{{' . $placeholder . '}}';
    }

    /**
     * Löst Fallback-Chain auf
     */
    private function resolveFallbackChain(string $chain, array $context): string
    {
        $paths = array_map('trim', explode('||', $chain));

        foreach ($paths as $path) {
            // Literale Strings (mit Quotes)
            if (preg_match('/^["\'](.+)["\']$/', $path, $matches)) {
                return $matches[1];
            }

            // Context-Pfad
            $value = $this->resolvePathWithArrays($path, $context);
            if ($value !== null && $value !== '') {
                return $this->convertToString($value);
            }
        }

        // Alle Fallbacks fehlgeschlagen
        return '';
    }

    /**
     * Löst Pfad mit Array-Support auf
     * 
     * Beispiele:
     * - step_5.result.jobs[0].company
     * - step_2.result.resume_id
     * - search_variants_list[0].what
     */
    private function resolvePathWithArrays(string $path, array $context): mixed
    {
        // Teile Pfad in Segmente (dots und brackets)
        $segments = $this->parsePathSegments($path);
        
        $value = $context;

        foreach ($segments as $segment) {
            // Array-Index: [0], [1], etc.
            if (preg_match('/^\[(\d+)\]$/', $segment, $matches)) {
                $index = (int)$matches[1];
                
                if (!is_array($value) || !isset($value[$index])) {
                    $this->logger->debug('Array index not found', [
                        'path' => $path,
                        'segment' => $segment,
                        'available_indices' => is_array($value) ? array_keys($value) : 'not_array'
                    ]);
                    return null;
                }
                
                $value = $value[$index];
                continue;
            }

            // Normaler Key
            if (is_array($value)) {
                if (isset($value[$segment])) {
                    $value = $value[$segment];
                } else {
                    $this->logger->debug('Key not found in context', [
                        'path' => $path,
                        'segment' => $segment,
                        'available_keys' => array_keys($value)
                    ]);
                    return null;
                }
            } else {
                $this->logger->debug('Cannot traverse non-array', [
                    'path' => $path,
                    'segment' => $segment,
                    'value_type' => gettype($value)
                ]);
                return null;
            }
        }

        return $value;
    }

    /**
     * Parsed Path in Segmente
     * 
     * "step_5.result.jobs[0].company" → ["step_5", "result", "jobs", "[0]", "company"]
     */
    private function parsePathSegments(string $path): array
    {
        $segments = [];
        $current = '';
        $inBracket = false;

        for ($i = 0; $i < strlen($path); $i++) {
            $char = $path[$i];

            if ($char === '[') {
                if ($current !== '') {
                    $segments[] = $current;
                    $current = '';
                }
                $inBracket = true;
                $current = '[';
            } elseif ($char === ']' && $inBracket) {
                $current .= ']';
                $segments[] = $current;
                $current = '';
                $inBracket = false;
            } elseif ($char === '.' && !$inBracket) {
                if ($current !== '') {
                    $segments[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * Konvertiert Wert zu String
     */
    private function convertToString(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        if (is_array($value)) {
            // Wenn Array nur einen Wert hat, nimm den
            if (count($value) === 1) {
                return $this->convertToString(reset($value));
            }
            
            // Sonst JSON
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prüft ob String unaufgelöste Platzhalter enthält
     */
    public function hasUnresolvedPlaceholders(mixed $data): bool
    {
        if (is_string($data)) {
            return preg_match('/\{\{[^}]+\}\}/', $data) === 1;
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                if ($this->hasUnresolvedPlaceholders($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Findet alle unaufgelösten Platzhalter
     */
    public function findUnresolvedPlaceholders(mixed $data): array
    {
        $unresolved = [];

        if (is_string($data)) {
            if (preg_match_all('/\{\{([^}]+)\}\}/', $data, $matches)) {
                $unresolved = array_merge($unresolved, $matches[1]);
            }
        } elseif (is_array($data)) {
            foreach ($data as $value) {
                $unresolved = array_merge(
                    $unresolved,
                    $this->findUnresolvedPlaceholders($value)
                );
            }
        }

        return array_unique($unresolved);
    }
}