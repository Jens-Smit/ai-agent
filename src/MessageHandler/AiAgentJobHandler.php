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
        $this->logger->info('AiAgentJobHandler: Job empfangen.', ['prompt' => $job->prompt]);

        try {
            $result = $this->aiAgentService->runPrompt($job->prompt, $job->options);
            $this->logger->info('AiAgentJobHandler: Job abgeschlossen.', ['result_summary' => [
                'status' => $result['status'] ?? null,
                'files_created' => $result['files_created'] ?? null
            ]]);
        } catch (\Throwable $e) {
            $this->logger->error('AiAgentJobHandler: Unhandled exception while handling job.', [
                'exception' => $e->getMessage(),
                'class' => get_class($e)
            ]);
        }
    }
}
