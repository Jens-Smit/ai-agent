<?php 
// src/MessageHandler/FrontendGeneratorJobHandler.php

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

            $this->logger->info('FrontendGeneratorJobHandler: Job erfolgreich abgeschlossen.');

        } catch (\Throwable $e) {
            $this->logger->error('FrontendGeneratorJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}