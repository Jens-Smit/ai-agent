<?php
// src/Service/Workflow/Context/ContextResolver.php
// 🔧 FIXED: Pipe-Fallback funktioniert jetzt korrekt!

declare(strict_types=1);

namespace App\Service\Workflow\Context;

use Psr\Log\LoggerInterface;

final class ContextResolver
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

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

    private function resolveString(string $str, array $context): string
    {
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function ($matches) use ($context) {
                return $this->resolvePlaceholder($matches[1], $context);
            },
            $str
        );
    }

    /**
     * 🔧 FIXED: Pipe-Fallback mit korrekter Null-Behandlung
     */
    private function resolvePlaceholder(string $placeholder, array $context): string
    {
        $placeholder = trim($placeholder);

        if (str_contains($placeholder, '|')) {
            return $this->resolveFallbackChain($placeholder, $context);
        }

        $value = $this->resolvePathWithArrays($placeholder, $context);

        if ($value !== null && $value !== '') {
            $this->logger->debug('Resolved placeholder', [
                'placeholder' => $placeholder,
                'value_preview' => is_scalar($value) ? substr((string)$value, 0, 50) : gettype($value)
            ]);
            return $this->convertToString($value);
        }

        $this->logger->warning('Placeholder not resolved', [
            'placeholder' => $placeholder,
            'available_keys' => array_keys($context)
        ]);

        return '{{' . $placeholder . '}}';
    }

    /**
     * 🔧 KRITISCH: Pipe-Fallback mit besserem Debugging
     */
    private function resolveFallbackChain(string $chain, array $context): string
    {
        $paths = explode('|', $chain);
        $paths = array_map('trim', $paths);

        $this->logger->info('🔍 RESOLVING FALLBACK CHAIN', [
            'chain' => $chain,
            'paths' => $paths,
            'context_keys' => array_keys($context)
        ]);

        foreach ($paths as $index => $path) {
            // Literale Strings
            if (preg_match('/^["\'](.+)["\']$/', $path, $matches)) {
                $this->logger->info('✅ Using literal fallback', ['value' => $matches[1]]);
                return $matches[1];
            }

            // 🔧 WICHTIG: Wir müssen den KOMPLETTEN Pfad debuggen!
            $this->logger->info('🔍 Trying path', [
                'index' => $index,
                'path' => $path,
                'context_structure' => $this->debugContextStructure($context, $path)
            ]);

            $value = $this->resolvePathWithArrays($path, $context);
            
            $this->logger->info('📊 Path result', [
                'path' => $path,
                'value' => $value,
                'is_null' => $value === null,
                'is_empty' => $value === '',
                'type' => gettype($value)
            ]);
            
            // 🔧 KRITISCH: Akzeptiere ersten NICHT-NULL UND NICHT-EMPTY Wert
            if ($value !== null && $value !== '') {
                $this->logger->info('✅ FOUND VALID VALUE', [
                    'path' => $path,
                    'value' => $value
                ]);
                return $this->convertToString($value);
            }
        }

        $this->logger->error('❌ ALL FALLBACKS FAILED', [
            'chain' => $chain,
            'tried_paths' => $paths
        ]);

        return '';
    }

    /**
     * 🔧 NEU: Debug-Helfer um Context-Struktur zu visualisieren
     */
    private function debugContextStructure(array $context, string $targetPath): array
    {
        $segments = $this->parsePathSegments($targetPath);
        $debug = [];
        
        $value = $context;
        foreach ($segments as $i => $segment) {
            $pathSoFar = implode('.', array_slice($segments, 0, $i + 1));
            
            if (is_array($value)) {
                $debug[$pathSoFar] = [
                    'exists' => array_key_exists($segment, $value),
                    'available_keys' => array_keys($value),
                    'type' => 'array'
                ];
                
                if (array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    break;
                }
            } else {
                $debug[$pathSoFar] = [
                    'error' => 'not_traversable',
                    'type' => gettype($value)
                ];
                break;
            }
        }
        
        return $debug;
    }

    /**
     * 🔧 FIXED: Robustere Pfad-Auflösung
     */
    private function resolvePathWithArrays(string $path, array $context): mixed
    {
        $segments = $this->parsePathSegments($path);
        $value = $context;

        $this->logger->debug('Resolving path segments', [
            'path' => $path,
            'segments' => $segments
        ]);

        foreach ($segments as $segment) {
            // Array-Index: [0], [1]
            if (preg_match('/^\[(\d+)\]$/', $segment, $matches)) {
                $index = (int)$matches[1];
                
                if (!is_array($value)) {
                    $this->logger->debug('❌ Value is not array for index access', [
                        'segment' => $segment,
                        'type' => gettype($value)
                    ]);
                    return null;
                }
                
                if (!isset($value[$index])) {
                    $this->logger->debug('❌ Array index not found', [
                        'index' => $index,
                        'available_indices' => array_keys($value)
                    ]);
                    return null;
                }
                
                $value = $value[$index];
                continue;
            }

            // Normaler Key
            if (!is_array($value)) {
                $this->logger->debug('❌ Cannot traverse non-array', [
                    'segment' => $segment,
                    'type' => gettype($value)
                ]);
                return null;
            }
            
            if (!array_key_exists($segment, $value)) {
                $this->logger->debug('❌ Key not found', [
                    'segment' => $segment,
                    'available_keys' => array_keys($value)
                ]);
                return null;
            }
            
            $value = $value[$segment];
        }

        return $value;
    }

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

    private function convertToString(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        if (is_array($value)) {
            if (count($value) === 1) {
                return $this->convertToString(reset($value));
            }
            
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

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