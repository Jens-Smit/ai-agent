<?php

namespace App\MessageHandler;

use App\Message\AiAgentJob;
use App\Service\AiAgentService;
use Psr\Log\LoggerInterface;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class AiAgentJobHandler
{
    public function __construct(
        private AiAgentService $aiAgentService,
        private LoggerInterface $logger,
        private MessageBusInterface $bus // Bus injizieren
    ) {}

    public function __invoke(AiAgentJob $job): void
    {
        $this->logger->info('AiAgentJobHandler: Job empfangen.', [
            'prompt' => $job->prompt,
            'originSessionId' => $job->originSessionId,
            'options' => $job->options
        ]);

        try {
            $this->aiAgentService->runPrompt(
                $job->prompt, 
                $job->originSessionId, 
                1
            );

            $this->logger->info('AiAgentJobHandler: Job erfolgreich abgeschlossen.');

        } catch (\Throwable $e) {
            $this->logger->error('AiAgentJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);

            // Wenn Rate Limit erkannt wird
            if (str_contains($e->getMessage(), 'Rate limit')) {
                $backoff = ($job->options['attempt'] ?? 1) * 5000; // 5s * attempt
                $this->logger->info("Job wird in {$backoff}ms neu eingeplant.");
                $this->bus->dispatch($job, [
                    new DelayStamp($backoff)
                ]);
            }
        }
    }
}
