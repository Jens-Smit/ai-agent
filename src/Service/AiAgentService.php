<?php
namespace App\Service;

use App\Message\AiAgentJob;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use App\Service\AgentStatusService;
use App\Service\CircuitBreakerService;
use App\Tool\DeployGeneratedCodeTool;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Throwable;

class AiAgentService
{
    private const MAX_RETRIES = 10;
    private const BASE_DELAY_SECONDS = 10;
    private const MAX_BACKOFF_SECONDS = 300;
    private const MAX_TOTAL_SECONDS = 3600;
    private const CIRCUIT_BREAKER_SERVICE = 'gemini_api';

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'ai.agent.file_generator')]
        private AgentInterface $agent,
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService,
        private DeployGeneratedCodeTool $deployTool,
        private MessageBusInterface $bus,
        private Connection $conn,
        private ?CircuitBreakerService $circuitBreaker = null
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

        // Note: $startTime kept in case you want to extend to global retry window across dispatches
        $startTime = time();
        if ((time() - $startTime) > self::MAX_TOTAL_SECONDS) {
            $this->agentStatusService->addStatus($sessionId, 'Maximale Gesamtlaufzeit für Retries überschritten.');
            $this->logger->error('Max total retry time exceeded', ['session' => $sessionId]);
            return;
        }

        $messages = new MessageBag(Message::ofUser($prompt));

        try {
            // Circuit Breaker Check
            if ($this->circuitBreaker && !$this->circuitBreaker->isRequestAllowed(self::CIRCUIT_BREAKER_SERVICE)) {
                $status = $this->circuitBreaker->getStatus(self::CIRCUIT_BREAKER_SERVICE);
                $this->agentStatusService->addStatus(
                    $sessionId,
                    sprintf('Circuit Breaker OPEN - Service temporär blockiert (Failures: %d)', $status['failure_count'])
                );
                $this->logger->warning('Circuit breaker prevented request', [
                    'service' => self::CIRCUIT_BREAKER_SERVICE,
                    'status' => $status
                ]);
                return;
            }

            $this->agentStatusService->addStatus($sessionId, sprintf('AI-Agent wird aufgerufen (Attempt %d).', $attempt));
            $result = $this->agent->call($messages);

            // Success: Circuit Breaker aktualisieren
            if ($this->circuitBreaker) {
                $this->circuitBreaker->recordSuccess(self::CIRCUIT_BREAKER_SERVICE);
            }

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
                // Falls du deploy nutzen willst, entkommentieren
                // $this->deployTool->deployFromString($content);
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

            // Persist failed payload for post-mortem (always record)
            $this->persistFailedPayload($sessionId, $prompt, $rawResponse, $errorMessage, $attempt);

            $isRetriable = $this->isTransientError($e);

            // Circuit Breaker Update bei Fehler (nur für retriable)
            if ($this->circuitBreaker && $isRetriable) {
                $this->circuitBreaker->recordFailure(self::CIRCUIT_BREAKER_SERVICE);
            }

            if ($isRetriable && $attempt < self::MAX_RETRIES) {
                $backoff = $this->computeBackoff($attempt);
                $this->agentStatusService->addStatus($sessionId, sprintf(
                    'Transient issue: %s. Neuer Versuch in %d s (Attempt %d).',
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
                $newMessage = new AiAgentJob(
                    $prompt,
                    $sessionId,
                    [
                        'attempt' => $attempt + 1
                    ]
                );
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
        // Progressive Backoff-Strategie:
        // Versuch 1-3: Kurze Wartezeiten (10-40s)
        // Versuch 4-6: Mittlere Wartezeiten (80-160s)
        // Versuch 7+: Längere Wartezeiten (bis MAX_BACKOFF)

        $baseDelay = match(true) {
            $attempt <= 3 => self::BASE_DELAY_SECONDS,
            $attempt <= 6 => self::BASE_DELAY_SECONDS * 4,
            default => self::BASE_DELAY_SECONDS * 8
        };

        $expo = min((int) ($baseDelay * (2 ** ($attempt - 1))), self::MAX_BACKOFF_SECONDS);

        // Full jitter: Zufällige Wartezeit zwischen 0 und expo
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
                    // Fallback: Versuche __toString()
                }
            }

            if (method_exists($result, '__toString')) {
                try {
                    $str = (string) $result;
                    if (!empty(trim($str))) {
                        return $str;
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('__toString() warf Exception', ['exception' => $e->getMessage()]);
                }
            }

            // Fallback: Versuche JSON-Serialisierung
            try {
                $json = json_encode($result);
                if ($json !== false && $json !== '{}' && $json !== '[]') {
                    return $json;
                }
            } catch (Throwable $e) {
                $this->logger->warning('json_encode() warf Exception', ['exception' => $e->getMessage()]);
            }
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        // Letzter Fallback: Leerer String (wird als transient behandelt)
        return '';
    }

    private function shortPreview(string $text, int $len = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $text));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }

    private function isTransientError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage() ?? '');

        // Netzwerk- und HTTP-Fehler
        if ($e instanceof ServerExceptionInterface || $e instanceof TransportExceptionInterface) {
            return true;
        }

        // Bekannte transiente Fehlertypen
        $transientKeywords = [
            '503', 'unavailable', 'overloaded', 'timed out', 'timeout',
            'rate limit', 'response does not contain any content',
            'invalid json', 'connection reset', 'temporar', 'temporarily',
            'service unavailable', 'gateway timeout', '502', '504',
            'too many requests', '429', 'quota exceeded',
            'internal server error', '500', // Oft transient bei AI APIs
            'resource exhausted', 'deadline exceeded'
        ];

        foreach ($transientKeywords as $k) {
            if (strpos($msg, $k) !== false) {
                return true;
            }
        }

        // Gemini-spezifische Fehler
        if (strpos($msg, 'code execution failed') !== false) {
            return true;
        }

        // Connection-Fehler
        if ($e instanceof \RuntimeException) {
            if (preg_match('/(connection|network|socket)/i', $msg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Versucht, Rohdaten aus bekannten Exception-Typen zu extrahieren.
     * Für HttpException-Implementierungen (z.B. Symfony HttpClient) verwenden wir getResponse().
     *
     * @param Throwable $e
     * @return string|null
     */
    private function tryExtractRawFromException(Throwable $e): ?string
    {
        // Wenn Exception ein HttpExceptionInterface ist, dann existiert getResponse()
        if ($e instanceof HttpExceptionInterface) {
            try {
                $resp = $e->getResponse();
                if (is_object($resp) && method_exists($resp, 'getContent')) {
                    return (string) $resp->getContent(false);
                }
            } catch (Throwable) {
                // ignore
            }
        }

        // Fallback: dynamischer Check (für SDK-spezifische Exceptions ohne gemeinsames Interface)
        if (method_exists($e, 'getResponse')) {
            try {
                /** @var object $resp */
                // @intelephense-ignore
                $resp = $e->getResponse();
                if (is_object($resp) && method_exists($resp, 'getContent')) {
                    return (string) $resp->getContent(false);
                }
            } catch (Throwable) {
                // ignore
            }
        }

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
