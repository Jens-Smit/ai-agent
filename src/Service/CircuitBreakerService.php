<?php
// src/Service/CircuitBreakerService.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Circuit Breaker Pattern Implementation
 * 
 * Verhindert wiederholte Anfragen an fehlerhafte Services
 * und gibt dem System Zeit zur Erholung.
 */
class CircuitBreakerService
{
    private const STATE_CLOSED = 'closed';    // Normal operation
    private const STATE_OPEN = 'open';        // Blocking requests
    private const STATE_HALF_OPEN = 'half_open'; // Testing recovery
    
    private const DEFAULT_FAILURE_THRESHOLD = 25;
    private const DEFAULT_TIMEOUT = 60; // seconds
    private const DEFAULT_SUCCESS_THRESHOLD = 2;

    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private int $successThreshold = self::DEFAULT_SUCCESS_THRESHOLD
    ) {}

    /**
     * Prüft ob eine Anfrage erlaubt ist
     */
    public function isRequestAllowed(string $serviceName): bool
    {
        $state = $this->getState($serviceName);
        
        return match($state) {
            self::STATE_CLOSED => true,
            self::STATE_HALF_OPEN => $this->shouldAttemptRecovery($serviceName),
            self::STATE_OPEN => $this->shouldTransitionToHalfOpen($serviceName),
            default => true
        };
    }

    /**
     * Registriert erfolgreiche Anfrage
     */
    public function recordSuccess(string $serviceName): void
    {
        $state = $this->getState($serviceName);
        
        if ($state === self::STATE_HALF_OPEN) {
            $successCount = $this->incrementSuccessCount($serviceName);
            
            if ($successCount >= $this->successThreshold) {
                $this->transitionToState($serviceName, self::STATE_CLOSED);
                $this->logger->info('Circuit breaker closed', ['service' => $serviceName]);
            }
        } elseif ($state === self::STATE_CLOSED) {
            // Reset failure count bei Erfolg
            $this->resetFailureCount($serviceName);
        }
    }

    /**
     * Registriert fehlgeschlagene Anfrage
     */
    public function recordFailure(string $serviceName): void
    {
        $state = $this->getState($serviceName);
        
        if ($state === self::STATE_HALF_OPEN) {
            // Bei Fehler im half-open state sofort zurück zu open
            $this->transitionToState($serviceName, self::STATE_OPEN);
            $this->logger->warning('Circuit breaker reopened', ['service' => $serviceName]);
            return;
        }
        
        if ($state === self::STATE_CLOSED) {
            $failureCount = $this->incrementFailureCount($serviceName);
            
            if ($failureCount >= $this->failureThreshold) {
                $this->transitionToState($serviceName, self::STATE_OPEN);
                $this->logger->error('Circuit breaker opened', [
                    'service' => $serviceName,
                    'failures' => $failureCount
                ]);
            }
        }
    }

    /**
     * Gibt aktuellen Status zurück
     */
    public function getStatus(string $serviceName): array
    {
        $state = $this->getState($serviceName);
        $failureCount = $this->getFailureCount($serviceName);
        $successCount = $this->getSuccessCount($serviceName);
        
        return [
            'state' => $state,
            'failure_count' => $failureCount,
            'success_count' => $successCount,
            'threshold' => $this->failureThreshold,
            'timeout' => $this->timeout
        ];
    }

    /**
     * Setzt Circuit Breaker zurück
     */
    public function reset(string $serviceName): void
    {
        $this->cache->delete($this->getStateKey($serviceName));
        $this->cache->delete($this->getFailureCountKey($serviceName));
        $this->cache->delete($this->getSuccessCountKey($serviceName));
        $this->cache->delete($this->getLastFailureTimeKey($serviceName));
        
        $this->logger->info('Circuit breaker reset', ['service' => $serviceName]);
    }

    // Private Helper Methods

    private function getState(string $serviceName): string
    {
        return $this->cache->get(
            $this->getStateKey($serviceName),
            fn() => self::STATE_CLOSED
        );
    }

    private function transitionToState(string $serviceName, string $newState): void
    {
        $this->cache->get(
            $this->getStateKey($serviceName),
            function(ItemInterface $item) use ($newState) {
                $item->expiresAfter($this->timeout * 2);
                return $newState;
            }
        );
        
        if ($newState === self::STATE_CLOSED) {
            $this->resetFailureCount($serviceName);
            $this->resetSuccessCount($serviceName);
        }
        
        if ($newState === self::STATE_OPEN) {
            $this->setLastFailureTime($serviceName);
        }
    }

    private function shouldTransitionToHalfOpen(string $serviceName): bool
    {
        $lastFailureTime = $this->getLastFailureTime($serviceName);
        
        if ($lastFailureTime === null) {
            return false;
        }
        
        $elapsed = time() - $lastFailureTime;
        
        if ($elapsed >= $this->timeout) {
            $this->transitionToState($serviceName, self::STATE_HALF_OPEN);
            $this->logger->info('Circuit breaker transitioned to half-open', [
                'service' => $serviceName
            ]);
            return true;
        }
        
        return false;
    }

    private function shouldAttemptRecovery(string $serviceName): bool
    {
        // Im half-open state: Nur begrenzte Anzahl an Tests erlauben
        $successCount = $this->getSuccessCount($serviceName);
        return $successCount < $this->successThreshold;
    }

    private function incrementFailureCount(string $serviceName): int
    {
        $key = $this->getFailureCountKey($serviceName);
        
        return $this->cache->get($key, function(ItemInterface $item) {
            $item->expiresAfter($this->timeout * 10);
            return 1;
        }) + 1;
    }

    private function incrementSuccessCount(string $serviceName): int
    {
        $key = $this->getSuccessCountKey($serviceName);
        
        $count = $this->cache->get($key, function(ItemInterface $item) {
            $item->expiresAfter($this->timeout);
            return 0;
        });
        
        $newCount = $count + 1;
        $this->cache->delete($key);
        $this->cache->get($key, function(ItemInterface $item) use ($newCount) {
            $item->expiresAfter($this->timeout);
            return $newCount;
        });
        
        return $newCount;
    }

    private function resetFailureCount(string $serviceName): void
    {
        $this->cache->delete($this->getFailureCountKey($serviceName));
    }

    private function resetSuccessCount(string $serviceName): void
    {
        $this->cache->delete($this->getSuccessCountKey($serviceName));
    }

    private function getFailureCount(string $serviceName): int
    {
        return $this->cache->get(
            $this->getFailureCountKey($serviceName),
            fn() => 0
        );
    }

    private function getSuccessCount(string $serviceName): int
    {
        return $this->cache->get(
            $this->getSuccessCountKey($serviceName),
            fn() => 0
        );
    }

    private function setLastFailureTime(string $serviceName): void
    {
        $this->cache->get(
            $this->getLastFailureTimeKey($serviceName),
            function(ItemInterface $item) {
                $item->expiresAfter($this->timeout * 2);
                return time();
            }
        );
    }

    private function getLastFailureTime(string $serviceName): ?int
    {
        try {
            return $this->cache->get(
                $this->getLastFailureTimeKey($serviceName),
                fn() => null
            );
        } catch (\Throwable) {
            return null;
        }
    }

    // Cache Key Generators

    private function getStateKey(string $serviceName): string
    {
        return "circuit_breaker.state.{$serviceName}";
    }

    private function getFailureCountKey(string $serviceName): string
    {
        return "circuit_breaker.failures.{$serviceName}";
    }

    private function getSuccessCountKey(string $serviceName): string
    {
        return "circuit_breaker.successes.{$serviceName}";
    }

    private function getLastFailureTimeKey(string $serviceName): string
    {
        return "circuit_breaker.last_failure.{$serviceName}";
    }
}