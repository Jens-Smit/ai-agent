<?php

namespace App\MessageHandler;

use App\Message\AiAgentJob;
use App\Service\AiAgentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class AiAgentJobHandler
{
    // Nehme an, dass AiAgentService::runPrompt() nun 
    // die Agent-Optionen als drittes Argument akzeptiert.
    public function __construct(
        private AiAgentService $aiAgentService,
        private LoggerInterface $logger,
        private MessageBusInterface $bus
    ) {}

    public function __invoke(AiAgentJob $job): void
    {
        // Den Versuchszähler für Retries erhöhen
        $job->options['attempt'] = ($job->options['attempt'] ?? 0) + 1;

        $this->logger->info('AiAgentJobHandler: Job empfangen.', [
            'prompt' => $job->prompt,
            'originSessionId' => $job->originSessionId,
            'options' => $job->options
        ]);

        try {
            // 🛑 WICHTIG: Die Options werden an den Service übergeben!
            $this->aiAgentService->runPrompt(
                $job->prompt,
                $job->originSessionId,
                $job->options['attempt'] ?? 1, // Optional: Übergeben Sie den $attempt-Wert als int
                $job->options
            );

            $this->logger->info('AiAgentJobHandler: Job erfolgreich abgeschlossen.');

        } catch (\Throwable $e) {
            $this->logger->error('AiAgentJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);

            // Rate Limit Retry-Logik beibehalten
            if (str_contains($e->getMessage(), 'Rate limit')) {
                $backoff = $job->options['attempt'] * 5000; 
                $this->logger->info("Job wird in {$backoff}ms neu eingeplant.");
                
                // Job mit aktualisierten Optionen und Delay erneut dispatchen
                $this->bus->dispatch($job, [
                    new DelayStamp($backoff)
                ]);
            }
        }
    }
}