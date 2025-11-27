<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FrontendGeneratorJob;
use App\Service\AiAgentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FrontendGeneratorJobHandler
{
    public function __construct(
        private AiAgentService $aiAgentService,
        private LoggerInterface $logger
    ) {}

    public function __invoke(FrontendGeneratorJob $job): void
    {
        $this->logger->info('FrontendGeneratorJobHandler: Job empfangen.', [
            'prompt_preview' => substr($job->prompt, 0, 50) . '...',
            'originSessionId' => $job->originSessionId,
            'options' => $job->options
        ]);

        try {
            // Ermittle den aktuellen Versuch aus den Optionen (Standard: 1)
            $attempt = $job->options['attempt'] ?? 1;

            // Führe den Prompt aus
            $this->aiAgentService->runPrompt(
                $job->prompt,
                $job->originSessionId, // Nutze die ursprüngliche Session-ID für Kontinuität
                (int) $attempt
            );

            $this->logger->info('FrontendGeneratorJobHandler: Job erfolgreich abgeschlossen.');

        } catch (\Throwable $e) {
            $this->logger->error('FrontendGeneratorJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            // WICHTIG: Exception weiterwerfen, damit Symfony Messenger weiß, dass der Job fehlgeschlagen ist.
            // Dies ist notwendig, damit Retries auf Transport-Ebene (falls konfiguriert) greifen
            // oder der Job in die failed queue verschoben wird.
            throw $e;
        }
    }
}