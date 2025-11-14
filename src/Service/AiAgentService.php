<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use App\Service\AgentStatusService;
use App\Tool\DeployGeneratedCodeTool;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Throwable;

class AiAgentService
{
    private const MAX_RETRIES = 10;
    private const BASE_DELAY_SECONDS = 10; // Baseline für Backoff (für lokale Tests anpassen)
    private const MAX_BACKOFF_SECONDS = 300; // Max Wartezeit zwischen Retries
    private const MAX_TOTAL_SECONDS = 3600; // max Gesamtlaufzeit des Retries (safety)

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'ai.agent.file_generator')]
        private AgentInterface $agent,
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService,
        private DeployGeneratedCodeTool $deployTool,
        private MessageBusInterface $bus,
        private Connection $conn
    ) {}

    /**
     * Run prompt. Non-blocking retry approach: re-dispatches job with DelayStamp on transient errors.
     * If $sessionId is null a new UUID is generated.
     */
    public function runPrompt(string $prompt, ?string $sessionId = null, int $attempt = 1): void
    {
        $sessionId = $sessionId ?: Uuid::v4()->toRfc4122();

        // start / heartbeat
        if ($attempt === 1) {
            $this->agentStatusService->clearStatuses($sessionId);
            $this->agentStatusService->addStatus($sessionId, 'Job gestartet für Prompt.');
        } else {
            $this->agentStatusService->addStatus($sessionId, sprintf('Job requeued (Attempt %d).', $attempt));
        }

        $startTime = time();
        if ((time() - $startTime) > self::MAX_TOTAL_SECONDS) {
            $this->agentStatusService->addStatus($sessionId, 'Maximale Gesamtlaufzeit für Retries überschritten.');
            $this->logger->error('Max total retry time exceeded', ['session' => $sessionId]);
            return;
        }

        $messages = new MessageBag(Message::ofUser($prompt));

        try {
            $this->agentStatusService->addStatus($sessionId, sprintf('AI-Agent wird aufgerufen (Attempt %d).', $attempt));
            $result = $this->agent->call($messages);

            // Try to extract content safely
            $content = $this->extractContentSafely($result);

            // If empty, treat as transient
            if ($content === '') {
                throw new \RuntimeException('Response does not contain any content.');
            }

            // Success: persist RESULT and attempt deployment
            $this->agentStatusService->addStatus($sessionId, 'RESULT: ' . $this->shortPreview($content, 300));
            $this->logger->info('Agent erfolgreich ausgeführt', ['session' => $sessionId, 'preview' => $this->shortPreview($content, 200)]);

            // Attempt deployment, mark DEPLOYMENT outcome
            try {
                $this->deployTool->deployFromString($content);
                $this->agentStatusService->addStatus($sessionId, 'DEPLOYMENT: success');
            } catch (Throwable $e) {
                $this->agentStatusService->addStatus($sessionId, 'ERROR: Deploy failed: ' . $this->shortPreview($e->getMessage(), 200));
                $this->logger->error('Deploy failed', ['session' => $sessionId, 'err' => $e->getMessage()]);
            }

            return;
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage() ?? get_class($e);

            // Best-effort raw extraction from exception or result
            $rawResponse = $this->tryExtractRawFromException($e) ?? '';

            // Persist failed payload for post-mortem
            $this->persistFailedPayload($sessionId, $prompt, $rawResponse, $errorMessage, $attempt);

            $isRetriable = $this->isTransientError($e);

            if ($isRetriable && $attempt < self::MAX_RETRIES) {
                $backoff = $this->computeBackoff($attempt);
                $this->agentStatusService->addStatus($sessionId, sprintf(
                    'Fehler (Retriable): %s. Requeued in %d s (Attempt %d).',
                    $this->shortPreview($errorMessage, 140),
                    $backoff,
                    $attempt + 1
                ));

                $this->logger->warning('Requeueing AiAgentJob', [
                    'session' => $sessionId,
                    'attempt' => $attempt,
                    'backoff' => $backoff,
                    'err' => $errorMessage,
                ]);

                // Re-dispatch job with increased attempt and DelayStamp (milliseconds)
                $newMessage = new \App\Message\AiAgentJob($prompt, $sessionId, [], $attempt + 1);
                $this->bus->dispatch($newMessage, [new DelayStamp($backoff * 1000)]);
                return;
            }

            // Non-retriable or max attempts reached: mark ERROR and stop
            $this->agentStatusService->addStatus($sessionId, 'ERROR: ' . $this->shortPreview($errorMessage, 300));
            $this->logger->error('AiAgentService unrecoverable error', [
                'session' => $sessionId,
                'attempt' => $attempt,
                'err' => $errorMessage,
                'trace' => $e->getTraceAsString()
            ]);

            return;
        }
    }

    private function computeBackoff(int $attempt): int
    {
        // Exponentielles Backoff mit Full jitter
        $expo = min(self::BASE_DELAY_SECONDS * (2 ** ($attempt - 1)), self::MAX_BACKOFF_SECONDS);
        return (int) round(mt_rand(0, 1000) / 1000 * $expo);
    }

    private function extractContentSafely(mixed $result): string
    {
        if (is_object($result)) {
            if (method_exists($result, 'getContent')) {
                try {
                    $c = $result->getContent();
                    return is_string($c) ? $c : (is_scalar($c) ? (string) $c : json_encode($c));
                } catch (Throwable $e) {
                    $this->logger->warning('getContent() warf Exception', ['exception' => $e->getMessage()]);
                    return '';
                }
            }

            if (method_exists($result, '__toString')) {
                try {
                    return (string) $result;
                } catch (Throwable $e) {
                    return '';
                }
            }

            // Fallback: serialisieren
            try {
                return json_encode($result);
            } catch (Throwable $e) {
                return '';
            }
        }

        return is_scalar($result) ? (string) $result : ($result === null ? '' : json_encode($result));
    }

    private function shortPreview(string $text, int $len = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $text));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }

    private function isTransientError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage() ?? '');

        if ($e instanceof ServerExceptionInterface || $e instanceof TransportExceptionInterface) {
            return true;
        }

        $transientKeywords = ['503', 'unavailable', 'overloaded', 'timed out', 'timeout', 'rate limit', 'response does not contain any content', 'invalid json', 'connection reset', 'temporar'];
        foreach ($transientKeywords as $k) {
            if (strpos($msg, $k) !== false) {
                return true;
            }
        }

        // "Code execution failed" can be transient depending on provider; allow one or two retries
        if (strpos($msg, 'code execution failed') !== false) {
            return true;
        }

        return false;
    }

    private function tryExtractRawFromException(Throwable $e): ?string
    {
        // If exception exposes a response, try to pull it out (SDK specific)
        if (method_exists($e, 'getResponse')) {
            try {
                $resp = $e->getResponse();
                if (is_object($resp) && method_exists($resp, 'getContent')) {
                    return (string) $resp->getContent(false);
                }
            } catch (Throwable) {
                // ignore
            }
        }

        // fallback to message
        return $e->getMessage();
    }

    private function persistFailedPayload(string $sessionId, string $request, string $response, string $errorMessage, int $attempt): void
    {
        try {
            $this->conn->insert('failed_payloads', [
                'session_id' => $sessionId,
                'request' => substr($request, 0, 65535),
                'response' => substr($response, 0, 65535),
                'error_message' => substr($errorMessage, 0, 1024),
                'attempt' => $attempt,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Log but don't fail the worker because of persistence problems
            $this->logger->error('Failed to persist failed_payloads', [
                'session' => $sessionId,
                'err' => $e->getMessage()
            ]);
        }
    }
}
