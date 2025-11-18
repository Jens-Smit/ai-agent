<?php
// src/Messenger/Middleware/AgentStatusMiddleware.php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Message\AiAgentJob;
use App\Message\FrontendGeneratorJob;
use App\Message\PersonalAssistantJob;
use App\Service\AgentStatusService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * Agent Status Middleware
 * 
 * Tracked automatisch den Status aller Agent-Jobs:
 * - Queued
 * - Processing
 * - Completed
 * - Failed
 */
final class AgentStatusMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AgentStatusService $statusService,
        private LoggerInterface $logger
    ) {}
    
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        
        // Nur für Agent-Jobs
        if (!$this->isAgentJob($message)) {
            return $stack->next()->handle($envelope, $stack);
        }
        
        $sessionId = $this->extractSessionId($message);
        
        if (!$sessionId) {
            return $stack->next()->handle($envelope, $stack);
        }
        
        // Status: Processing (nur wenn Message tatsächlich verarbeitet wird)
        $isReceived = $envelope->last(ReceivedStamp::class) !== null;
        
        if ($isReceived) {
            $this->statusService->addStatus(
                $sessionId,
                sprintf('🔄 Job wird verarbeitet (Worker: %s)', gethostname())
            );
            
            $startTime = microtime(true);
        }
        
        try {
            // Verarbeite Message
            $envelope = $stack->next()->handle($envelope, $stack);
            
            if ($isReceived && isset($startTime)) {
                $duration = round(microtime(true) - $startTime, 2);
                
                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('✅ Job erfolgreich abgeschlossen (Dauer: %ss)', $duration)
                );
            }
            
            return $envelope;
            
        } catch (\Throwable $e) {
            // Prüfe ob Message zum Failed-Transport geht
            $isFailing = $envelope->last(SentToFailureTransportStamp::class) !== null;
            
            if ($isFailing) {
                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('❌ Job fehlgeschlagen: %s', $e->getMessage())
                );
            } else {
                // Wird retried
                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('⚠️ Job wird wiederholt (Fehler: %s)', $e->getMessage())
                );
            }
            
            throw $e;
        }
    }
    
    private function isAgentJob(object $message): bool
    {
        return $message instanceof AiAgentJob ||
               $message instanceof PersonalAssistantJob ||
               $message instanceof FrontendGeneratorJob;
    }
    
    private function extractSessionId(object $message): ?string
    {
        if ($message instanceof PersonalAssistantJob || 
            $message instanceof FrontendGeneratorJob) {
            return $message->sessionId;
        }
        
        if ($message instanceof AiAgentJob) {
            return $message->originSessionId;
        }
        
        return null;
    }
}