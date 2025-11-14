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
            'options' => $job->options
        ]);

        try {
            $result = $this->aiAgentService->runPrompt($job->prompt, $job->options);

            $this->logResultSummary($result);

        } catch (\Throwable $e) {
            $this->logger->error('AiAgentJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);
        }
    }

    private function logResultSummary(mixed $result): void
    {
        if (!is_array($result)) {
            $this->logger->warning('AiAgentJobHandler: Unerwarteter Datentyp vom Service.', [
                'type' => gettype($result),
                'value' => $result
            ]);
            return;
        }

        $this->logger->info('AiAgentJobHandler: Job abgeschlossen.', [
            'status' => $result['status'] ?? 'unknown',
            'files_created' => $result['files_created'] ?? []
        ]);
    }
}
