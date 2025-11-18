<?php
// src/Messenger/Middleware/CircuitBreakerMiddleware.php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Service\CircuitBreakerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Circuit Breaker Middleware für Messenger
 * 
 * Schützt die Agents vor Überlastung durch:
 * - Rate Limiting pro Agent-Typ
 * - Automatische Backoff bei Fehlern
 * - Service-spezifische Circuit Breaker
 */
final class CircuitBreakerMiddleware implements MiddlewareInterface
{
    private const AGENT_SERVICE_MAP = [
        'App\Message\AiAgentJob' => 'gemini_api',
        'App\Message\PersonalAssistantJob' => 'gemini_api',
        'App\Message\FrontendGeneratorJob' => 'gemini_api',
    ];
    
    public function __construct(
        private CircuitBreakerService $circuitBreaker,
        private LoggerInterface $logger
    ) {}
    
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $messageClass = get_class($message);
        
        // Ermittle zugehörigen Service
        $serviceName = self::AGENT_SERVICE_MAP[$messageClass] ?? null;
        
        if (!$serviceName) {
            // Keine Circuit Breaker Protection für diesen Message-Type
            return $stack->next()->handle($envelope, $stack);
        }
        
        // Prüfe Circuit Breaker Status
        if (!$this->circuitBreaker->isRequestAllowed($serviceName)) {
            $status = $this->circuitBreaker->getStatus($serviceName);
            
            $this->logger->warning('Circuit breaker prevented message processing', [
                'message_class' => $messageClass,
                'service' => $serviceName,
                'circuit_status' => $status
            ]);
            
            // Verzögere Message um Circuit Breaker Timeout
            $delay = ($status['timeout'] ?? 60) * 1000; // In Millisekunden
            
            return $envelope->with(new DelayStamp($delay));
        }
        
        try {
            // Verarbeite Message
            $envelope = $stack->next()->handle($envelope, $stack);
            
            // Erfolg -> Circuit Breaker aktualisieren
            $this->circuitBreaker->recordSuccess($serviceName);
            
            return $envelope;
            
        } catch (\Throwable $e) {
            // Fehler -> Circuit Breaker aktualisieren
            $this->circuitBreaker->recordFailure($serviceName);
            
            $this->logger->error('Message processing failed, circuit breaker updated', [
                'message_class' => $messageClass,
                'service' => $serviceName,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}