<?php
// src/Service/FallbackAgentWrapper.php
// Dieser Wrapper handhabt den Fallback automatisch

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wrapper der automatisch zwischen Gemini und OpenAI wechselt bei Rate Limits
 */
final class FallbackAgentWrapper
{
    private bool $primaryFailed = false;
    private int $primaryFailCount = 0;
    private ?\DateTimeImmutable $primaryCooldownUntil = null;
    
    private const COOLDOWN_SECONDS = 60; // Nach 60s wieder Primary versuchen
    private const MAX_PRIMARY_FAILS = 3;  // Nach 3 Fails auf Cooldown

    public function __construct(
        #[Autowire(service: 'ai.agent.file_generator')]
        private AgentInterface $primaryAgent,
        
        #[Autowire(service: 'ai.agent.file_generator_huggingface')]
        private AgentInterface $fallbackAgent,
        
        private LoggerInterface $logger,
        private AgentStatusService $statusService
    ) {}

    /**
     * Ruft den Agent auf mit automatischem Fallback
     */
    public function call(MessageBag $messages, string $sessionId): mixed
    {
        // Prüfe ob Primary wieder verfügbar
        if ($this->shouldUsePrimary()) {
            return $this->tryWithFallback($messages, $sessionId);
        }
        
        // Direkt Fallback nutzen (Primary im Cooldown)
        $this->statusService->addStatus($sessionId, '🔄 Verwende OpenAI (Gemini im Cooldown)');
        return $this->fallbackAgent->call($messages);
    }

    private function tryWithFallback(MessageBag $messages, string $sessionId): mixed
    {
        try {
            $this->statusService->addStatus($sessionId, '🤖 Verwende Gemini...');
            $result = $this->primaryAgent->call($messages);
            
            // Erfolg - Reset Fail Counter
            $this->primaryFailCount = 0;
            $this->primaryFailed = false;
            
            return $result;
            
        } catch (\Throwable $e) {
            if ($this->isRateLimitError($e)) {
                $this->primaryFailCount++;
                
                $this->logger->warning('Gemini rate limit hit', [
                    'session' => $sessionId,
                    'fail_count' => $this->primaryFailCount
                ]);
                
                // Nach zu vielen Fails: Cooldown setzen
                if ($this->primaryFailCount >= self::MAX_PRIMARY_FAILS) {
                    $this->primaryCooldownUntil = new \DateTimeImmutable(
                        '+' . self::COOLDOWN_SECONDS . ' seconds'
                    );
                    $this->primaryFailed = true;
                    
                    $this->logger->info('Gemini in cooldown', [
                        'until' => $this->primaryCooldownUntil->format('H:i:s')
                    ]);
                }
                
                // Fallback versuchen
                $this->statusService->addStatus(
                    $sessionId, 
                    '⚠️ Gemini Rate Limit - wechsle zu OpenAI...'
                );
                
                return $this->fallbackAgent->call($messages);
            }
            
            // Andere Fehler durchreichen
            throw $e;
        }
    }

    private function shouldUsePrimary(): bool
    {
        // Primary nie gefailed -> nutzen
        if (!$this->primaryFailed) {
            return true;
        }
        
        // Cooldown abgelaufen?
        if ($this->primaryCooldownUntil !== null && 
            new \DateTimeImmutable() > $this->primaryCooldownUntil) {
            
            $this->logger->info('Gemini cooldown ended, trying primary again');
            $this->primaryFailed = false;
            $this->primaryFailCount = 0;
            $this->primaryCooldownUntil = null;
            return true;
        }
        
        return false;
    }

    private function isRateLimitError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage() ?? '');
        return str_contains($msg, '429') 
            || str_contains($msg, 'rate limit')
            || str_contains($msg, 'quota exceeded')
            || str_contains($msg, 'too many requests')
            || str_contains($msg, 'resource exhausted');
    }
    
    /**
     * Gibt den aktuellen Status zurück
     */
    public function getStatus(): array
    {
        return [
            'using_fallback' => $this->primaryFailed,
            'primary_fail_count' => $this->primaryFailCount,
            'cooldown_until' => $this->primaryCooldownUntil?->format('c'),
        ];
    }
}