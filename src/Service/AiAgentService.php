<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\FrontendGeneratorJob;
use App\Service\AgentStatusService;
use App\Service\CircuitBreakerService;
use App\Tool\DeployGeneratedCodeTool;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

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
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'ai.agent.frontend_generator')]
        private AgentInterface $agent,
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService,
        private DeployGeneratedCodeTool $deployTool,
        private MessageBusInterface $bus,
        private ?CircuitBreakerService $circuitBreaker = null
    ) {}

    public function runPrompt(string $prompt, ?string $sessionId = null, int $attempt = 1): void
    {
        $sessionId = $sessionId ?: Uuid::v4()->toRfc4122();
        $startTime = time();

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
            $this->requeueJob($prompt, $sessionId, $attempt, $delay);
            return;
        }

        $messages = new MessageBag(Message::ofUser($prompt));

        try {
            $this->agentStatusService->addStatus($sessionId, sprintf('🤖 AI-Agent wird aufgerufen (Versuch %d)', $attempt));
            
            // Der eigentliche Call zum AI Agent
            $result = $this->agent->call($messages);

            if ($this->circuitBreaker) {
                $this->circuitBreaker->recordSuccess(self::CIRCUIT_BREAKER_SERVICE);
            }

            $content = $this->extractContentSafely($result);

            // WICHTIG: Result Variable explizit löschen, um Probleme mit dem TraceableAgent (Profiler)
            // beim Beenden des Requests zu vermeiden, falls das Objekt einen fehlerhaften State hat.
            unset($result);

            if (empty($content)) {
                // Manchmal ist der Content leer, wenn nur Tools aufgerufen wurden (was okay ist),
                // aber hier erwarten wir oft Text oder eine Zusammenfassung.
                // Wenn es kritisch ist, werfen wir einen Fehler.
                // Für diesen Generator ist ein leeres Ergebnis oft ein Fehler.
                throw new \RuntimeException('soft_empty_response');
            }

            $this->handleSuccess($sessionId, $content);

        } catch (\Throwable $e) {
            $this->handleError($e, $prompt, $sessionId, $attempt);
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
                $this->agentStatusService->addStatus($sessionId, "  • {$file}");
            }

            $this->agentStatusService->addStatus($sessionId, '📦 Erstelle Deployment-Paket...');
            try {
                $deploymentResult = $this->createDeploymentPackage($recentFiles);
                $this->agentStatusService->addStatus($sessionId, '✅ Deployment-Paket erstellt');
                $this->agentStatusService->addStatus($sessionId, 'DEPLOYMENT:' . $deploymentResult);
            } catch (\Throwable $e) {
                $this->logger->error('Deployment failed', ['error' => $e->getMessage()]);
                $this->agentStatusService->addStatus($sessionId, '⚠️ Deployment fehlgeschlagen: ' . $e->getMessage());
            }
        }

        $this->agentStatusService->addStatus($sessionId, 'RESULT:' . $this->shortPreview($content, 300));
    }

    private function handleError(\Throwable $e, string $prompt, string $sessionId, int $attempt): void
    {
        $errorMessage = $e->getMessage() ?? get_class($e);
        
        // Logging statt DB-Persistierung
        $this->logger->error('Agent Error', [
            'session' => $sessionId,
            'error' => $errorMessage,
            'attempt' => $attempt
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

            $this->logger->warning('Requeueing FrontendGeneratorJob', [
                'session' => $sessionId,
                'attempt' => $attempt,
                'backoff' => $backoff
            ]);

            $this->requeueJob($prompt, $sessionId, $attempt + 1, $backoff);
            return;
        }

        $this->agentStatusService->addStatus(
            $sessionId,
            'ERROR:' . $this->shortPreview($errorMessage, 300)
        );

        // Wirf den Fehler weiter, wenn es der letzte Versuch war, damit der Handler Bescheid weiß
        throw $e; 
    }

    private function requeueJob(string $prompt, string $sessionId, int $nextAttempt, int $delaySeconds): void
    {
        // Stelle sicher, dass wir FrontendGeneratorJob nutzen und den attempt in options übergeben
        $newMessage = new FrontendGeneratorJob(
            prompt: $prompt, 
            sessionId: Uuid::v4()->toRfc4122(), // Neue ID für den Job selbst, aber origin behalten
            options: ['attempt' => $nextAttempt],
            originSessionId: $sessionId
        );
        
        $this->bus->dispatch($newMessage, [new DelayStamp($delaySeconds * 1000)]);
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
        // STRATEGIE ÄNDERUNG:
        // Wir versuchen zuerst die Rohdaten (JSON/Array) zu lesen, BEVOR wir getContent() aufrufen.
        // Der Grund ist, dass $result->getContent() im Symfony/Gemini Bridge Code eine Exception wirft,
        // wenn kein Content da ist ("Response does not contain any content").
        // Wenn wir diese Methode vermeiden können, verhindern wir, dass der TraceableAgent (Profiler)
        // später versucht, sie erneut aufzurufen und crasht.

        // 1. Versuche JSON/Array Konvertierung (Sicherer Weg)
        try {
            $arr = json_decode(json_encode($result), true);
            // Typische Gemini Struktur prüfen
            $text = $arr['candidates'][0]['content']['parts'][0]['text']
                ?? $arr['contents'][0]['parts'][0]['text']
                ?? null;
            
            if (!empty($text)) {
                return (string)$text;
            }
        } catch (\Throwable) {
            // Ignorieren und weiter versuchen
        }

        // 2. Versuche String Cast (__toString)
        if (is_object($result) && method_exists($result, '__toString')) {
            try {
                $s = (string) $result;
                if (!empty(trim($s))) return $s;
            } catch (\Throwable) {}
        }

        // 3. Letzter Ausweg: getContent() - Das ist die Methode, die Exception werfen kann
        if (is_object($result) && method_exists($result, 'getContent')) {
            try {
                $c = $result->getContent();
                if (!empty(trim((string)$c))) {
                    return (string)$c;
                }
            } catch (\Throwable $e) {
                // Ignore errors during extraction to allow soft_empty_response retry
                // especially "Response does not contain any content"
            }
        }

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
            'soft_empty_response',
            'code execution failed'
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