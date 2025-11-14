<?php

namespace App\MessageHandler;

use App\Message\AiAgentJob;
use App\Service\AiAgentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AiAgentJobHandler
{
    public function __construct(
        private AiAgentService $aiAgentService,
        private LoggerInterface $logger
    ) {}

    public function __invoke(AiAgentJob $job): void
    {
        $this->logger->info('AiAgentJobHandler: Job empfangen.', [
            'prompt' => $job->prompt,
            'originSessionId' => $job->originSessionId,
            'options' => $job->options
        ]);

        try {
            // Fix: Übergebe sessionId aus originSessionId oder null, nicht options
            $this->aiAgentService->runPrompt(
                $job->prompt, 
                $job->originSessionId, 
                1 // attempt = 1 für ersten Versuch
            );

            $this->logger->info('AiAgentJobHandler: Job erfolgreich abgeschlossen.');

        } catch (\Throwable $e) {
            $this->logger->error('AiAgentJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}