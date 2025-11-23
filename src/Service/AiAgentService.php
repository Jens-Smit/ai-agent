<?php
// src/Service/AiAgentService.php - KOMPLETT GEFIXT

declare(strict_types=1);

namespace App\Service;

use App\Message\AiAgentJob;
use App\Service\AgentStatusService;
use App\Service\CircuitBreakerService;
use App\Service\FallbackAgentWrapper; 
use App\Tool\DeployGeneratedCodeTool;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AiAgentService
{
    private const MAX_RETRIES = 10;
    private const BASE_DELAY_SECONDS = 10;
    private const MAX_BACKOFF_SECONDS = 300;
    private const MAX_TOTAL_SECONDS = 3600;
    private const CIRCUIT_BREAKER_SERVICE = 'gemini_api';

    private const NON_RETRIABLE_ERRORS = [
        'invalid_api_key',
        'permission_denied',
        'invalid_argument',
        'authentication_error',
        'quota_exceeded_final',
    ];

    public function __construct(
        // $agent: '@App\Service\FallbackAgentWrapper'
        private FallbackAgentWrapper $agent, // Fix: Use concrete type if FallbackAgentWrapper is used as the primary agent
        #[Autowire(service: 'ai.agent.file_generator_agent')]
        private AgentInterface $appDynamicAgent,
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService,
        private DeployGeneratedCodeTool $deployTool,
        private MessageBusInterface $bus,
        private Connection $conn,
        private ?CircuitBreakerService $circuitBreaker = null
    ) {}

    /**
     * @param int $attempt Dieser Parameter ist redundant, da $options['attempt'] verwendet wird. Er wird beibehalten, um die Aufrufer nicht zu brechen.
     */
    public function runPrompt(string $prompt, ?string $sessionId = null, int $attempt = 1, array $options = []): void
    {
        $sessionId = $sessionId ?: Uuid::v4()->toRfc4122();
        $startTime = time();
        $attempt = $options['attempt'] ?? 1; // Priorisiere den Wert aus $options

        if ($attempt === 1) {
            $this->agentStatusService->clearStatuses($sessionId);
            $this->agentStatusService->addStatus($sessionId, '🚀 DevAgent Job gestartet');
            $this->agentStatusService->addStatus($sessionId, '📝 Prompt wird analysiert...');
        } else {
            $this->agentStatusService->addStatus($sessionId, sprintf('🔄 Retry-Versuch %d/%d', $attempt, self::MAX_RETRIES));
        }

        if ((time() - $startTime) > self::MAX_TOTAL_SECONDS) {
            $this->agentStatusService->addStatus($sessionId, '⏱️ Maximale Gesamtlaufzeit überschritten');
            $this->logger->error('Max total retry time exceeded', ['session' => $sessionId]);
            return;
        }

        if ($this->circuitBreaker && !$this->circuitBreaker->isRequestAllowed(self::CIRCUIT_BREAKER_SERVICE)) {
            $status = $this->circuitBreaker->getStatus(self::CIRCUIT_BREAKER_SERVICE);
            $this->agentStatusService->addStatus(
                $sessionId,
                sprintf('⚠️ Circuit Breaker OPEN - Service blockiert (Failures: %d)', $status['failure_count'])
            );
            $this->logger->warning('Circuit breaker prevented request', [
                'service' => self::CIRCUIT_BREAKER_SERVICE,
                'status' => $status
            ]);

            $delay = ($status['timeout'] ?? 60) + $this->computeBackoff($attempt);
            // Übergabe der Optionen für den Retry
            $this->requeueJob($prompt, $sessionId, $options, $delay); 
            return;
        }

        $messages = new MessageBag();
        
        // 2. Prüfen, ob ein System Prompt überschrieben werden soll
        $callOptions = $options;
        if (isset($options['system_prompt'])) {
            // Füge den System Prompt als System Message hinzu
            $messages = $messages->with(Message::forSystem($options['system_prompt']));
            
            // WICHTIG: Entferne den Schlüssel, damit er NICHT an die AI-Plattform als Option geht!
            unset($callOptions['system_prompt']);
        }
        
        // 3. Füge die User Message hinzu
        $messages = $messages->with(Message::ofUser($prompt));

        try {
            $this->agentStatusService->addStatus($sessionId, sprintf('🤖 AI-Agent wird aufgerufen (Versuch %d)', $attempt));
            $this->logger->debug('AI-Agent wird aufgerufen mit Prompt', ['session' => $sessionId, 'prompt' => $prompt, 'options' => $options]);

            // WICHTIG: Übergabe der dynamischen Optionen ($options) an AgentInterface::call()
            
            unset($callOptions['attempt']); // Entfernt den internen Retry-Zähler

            // Falls Sie $options['prompt'] noch irgendwo verwenden, entfernen Sie es auch hier
            // unset($callOptions['prompt']); 

            $this->logger->debug('AI-Agent wird aufgerufen mit bereinigten Optionen', ['callOptions' => $callOptions]);

            $result = $this->appDynamicAgent->call($messages, $callOptions);

            if ($this->circuitBreaker) {
                $this->circuitBreaker->recordSuccess(self::CIRCUIT_BREAKER_SERVICE);
            }

            $content = $this->extractContentSafely($result);

            if (empty($content)) {
                throw new \RuntimeException('soft_empty_response');
            }

            $this->handleSuccess($sessionId, $content);

        } catch (\Throwable $e) {
            // FIX: Übergabe des vollständigen $options-Arrays zur Beibehaltung des System Prompts
            $this->handleError($e, $prompt, $sessionId, $options);
        }
    }

    private function handleSuccess(string $sessionId, string $content): void
    {
        $this->agentStatusService->addStatus($sessionId, '✅ Antwort vom AI-Agent erhalten');
        $this->agentStatusService->addStatus($sessionId, '📊 Analysiere generierte Dateien...');
        $this->logger->info('Agent execution successful', [
            'session' => $sessionId,
            'preview' => $this->shortPreview($content, 200)
        ]);

        $generatedCodeDir = __DIR__ . '/../../generated_code/';
        $recentFiles = $this->getRecentFiles($generatedCodeDir);

        if (!empty($recentFiles)) {
            $this->agentStatusService->addStatus(
                $sessionId,
                sprintf('📁 %d Dateien wurden erstellt', count($recentFiles))
            );

            foreach (array_slice($recentFiles, 0, 5) as $file) {
                $this->agentStatusService->addStatus($sessionId, "  • {$file}");
            }

            $this->agentStatusService->addStatus($sessionId, '📦 Erstelle Deployment-Paket...');
            $deploymentResult = $this->createDeploymentPackage($recentFiles);
            $this->agentStatusService->addStatus($sessionId, '✅ Deployment-Paket erstellt');
            $this->agentStatusService->addStatus($sessionId, 'DEPLOYMENT:' . $deploymentResult);
        }

        $this->agentStatusService->addStatus($sessionId, 'RESULT:' . $this->shortPreview($content, 300));
    }

    // FIX: Signatur aktualisiert, um $options zu empfangen und $attempt intern zu extrahieren
    private function handleError(\Throwable $e, string $prompt, string $sessionId, array $options): void
    {
        $attempt = $options['attempt'] ?? 1;
        $errorMessage = $e->getMessage() ?? get_class($e);
        $rawResponse = $this->tryExtractRawFromException($e) ?? '';
        $this->persistFailedPayload($sessionId, $prompt, $rawResponse, $errorMessage, $attempt);

        $this->logger->debug('Error stack trace', [
            'session' => $sessionId,
            'exception' => (string) $e
        ]);

        $isRetriable = $this->isTransientError($e);
        $isNonRetriable = $this->isNonRetriableError($errorMessage);

        if ($this->circuitBreaker && $isRetriable) {
            if (stripos($errorMessage, 'soft_empty_response') !== false) {
                if (method_exists($this->circuitBreaker, 'recordSoftFailure')) {
                    $this->circuitBreaker->recordSoftFailure(self::CIRCUIT_BREAKER_SERVICE);
                }
            } else {
                $this->circuitBreaker->recordFailure(self::CIRCUIT_BREAKER_SERVICE);
            }
        }

        if (!$isNonRetriable && $isRetriable && $attempt < self::MAX_RETRIES) {
            $backoff = $this->computeBackoff($attempt);
            $this->agentStatusService->addStatus(
                $sessionId,
                sprintf('⚠️ Vorübergehender Fehler: %s', $this->shortPreview($errorMessage, 140))
            );
            $this->agentStatusService->addStatus(
                $sessionId,
                sprintf('⏱️ Neuer Versuch in %ds (Attempt %d/%d)', $backoff, $attempt + 1, self::MAX_RETRIES)
            );

            $this->logger->warning('Requeueing AiAgentJob', [
                'session' => $sessionId,
                'attempt' => $attempt,
                'backoff' => $backoff,
                'error' => $errorMessage,
            ]);

            // FIX: Übergabe des vollständigen $options-Arrays an requeueJob
            $this->requeueJob($prompt, $sessionId, $options, $backoff);
            return;
        }

        $this->agentStatusService->addStatus(
            $sessionId,
            'ERROR:' . $this->shortPreview($errorMessage, 300)
        );

        $this->logger->error('AiAgentService unrecoverable error', [
            'session' => $sessionId,
            'attempt' => $attempt,
            'error' => $errorMessage,
            'is_retriable' => $isRetriable,
            'is_non_retriable' => $isNonRetriable
        ]);
    }

    // FIX: Signatur aktualisiert, um $options zu empfangen und den Versuchswert zu aktualisieren
    private function requeueJob(string $prompt, string $sessionId, array $options, int $delaySeconds): void
    {
        // $options enthält die ursprünglichen Overrides (z.B. System Prompt File).
        // Wir aktualisieren 'attempt' für den Retry.
        $options['attempt'] = ($options['attempt'] ?? 1) + 1;
        
        $newMessage = new AiAgentJob($prompt, $sessionId, $options); 
        $this->bus->dispatch($newMessage, [new DelayStamp($delaySeconds * 1000)]);
        $this->logger->info("Job wird in {$delaySeconds}s neu eingeplant. Nächster Versuch: " . $options['attempt']);
    }

    private function computeBackoff(int $attempt): int
    {
        $baseDelay = match(true) {
            $attempt <= 3 => self::BASE_DELAY_SECONDS,
            $attempt <= 6 => self::BASE_DELAY_SECONDS * 4,
            default => self::BASE_DELAY_SECONDS * 8
        };
        $expo = min((int) ($baseDelay * (2 ** ($attempt - 1))), self::MAX_BACKOFF_SECONDS);
        return (int) round(mt_rand(0, 1000) / 1000 * $expo);
    }

    private function extractContentSafely(mixed $result): string
    {
        if (is_object($result) && method_exists($result, 'getContent')) {
            try {
                $c = $result->getContent();
                if (!empty(trim((string)$c))) {
                    return (string)$c;
                }
            } catch (\Throwable) {}
        }

        try {
            $arr = json_decode(json_encode($result), true);
            $text = $arr['candidates'][0]['content']['parts'][0]['text']
                ?? $arr['contents'][0]['parts'][0]['text']
                ?? null;
            if (!empty($text)) return $text;
        } catch (\Throwable) {}

        if (is_object($result) && method_exists($result, '__toString')) {
            try {
                $s = (string) $result;
                if (!empty(trim($s))) return $s;
            } catch (\Throwable) {}
        }

        try {
            $json = json_encode($result);
            if (!empty($json) && $json !== '{}' && $json !== '[]') return $json;
        } catch (\Throwable) {}

        if (is_scalar($result)) return (string)$result;

        return '';
    }

    private function shortPreview(string $text, int $len = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $text));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }

    private function isTransientError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage() ?? '');
        if ($e instanceof ServerExceptionInterface || $e instanceof TransportExceptionInterface) return true;

        $transientKeywords = [
            '503','unavailable','overloaded','timed out','timeout',
            'rate limit','response does not contain any content',
            'invalid json','connection reset','temporar','temporarily',
            'service unavailable','gateway timeout','502','504',
            'too many requests','429','quota exceeded',
            'internal server error','500','resource exhausted','deadline exceeded',
            'soft_empty_response'
        ];

        foreach ($transientKeywords as $k) {
            if (strpos($msg, $k) !== false) return true;
        }

        return false;
    }

    private function isNonRetriableError(string $errorMessage): bool
    {
        $msg = strtolower($errorMessage);
        foreach (self::NON_RETRIABLE_ERRORS as $pattern) {
            if (strpos($msg, $pattern) !== false) return true;
        }
        return false;
    }

    private function tryExtractRawFromException(\Throwable $e): ?string
    {
        if ($e instanceof HttpExceptionInterface) {
            try {
                $resp = $e->getResponse();
                if (is_object($resp) && method_exists($resp, 'getContent')) return (string) $resp->getContent(false);
            } catch (\Throwable) {}
        }
        if (method_exists($e, 'getResponse')) {
            try {
                $resp = $e->getResponse();
                if (is_object($resp) && method_exists($resp, 'getContent')) return (string) $resp->getContent(false);
            } catch (\Throwable) {}
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
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist failed_payloads', ['session' => $sessionId, 'err' => $e->getMessage()]);
        }
    }

    private function getRecentFiles(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $recentFiles = [];
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filepath = $dir . $file;
            if (is_file($filepath) && filemtime($filepath) > time() - 120) $recentFiles[] = $file;
        }
        return $recentFiles;
    }

    private function createDeploymentPackage(array $files): string
    {
        $filesToDeploy = [];
        foreach ($files as $file) {
            $targetPath = $this->determineTargetPath($file);
            $filesToDeploy[] = ['source_file' => $file, 'target_path' => $targetPath];
        }
        return $this->deployTool->__invoke($filesToDeploy);
    }

    private function determineTargetPath(string $file): string
    {
        if (str_ends_with($file, 'Test.php')) return 'tests/' . $file;
        if (str_ends_with($file, '.php')) return 'src/' . $file;
        if (str_ends_with($file, '.yaml') || str_ends_with($file, '.json')) return 'config/' . $file;
        if (preg_match('/^Version\d{14}\.php$/', $file)) return 'migrations/' . $file;
        return 'generated_code/' . $file;
    }
}