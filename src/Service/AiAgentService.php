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
use Throwable;

class AiAgentService
{
    private const MAX_RETRIES = 50;
    private const BASE_DELAY_SECONDS = 5; // Baseline für Backoff (für lokale Tests anpassen)
    private const MAX_BACKOFF_SECONDS = 300; // Max Wartezeit zwischen Retries
    private const MAX_TOTAL_SECONDS = 3600; // max Gesamtlaufzeit des Retries (safety)

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'ai.agent.file_generator')]
        private AgentInterface $agent,
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService,
        private DeployGeneratedCodeTool $deployTool
    ) {}

    public function runPrompt(string $prompt): void
    {
        $this->agentStatusService->clearStatuses();
        $this->agentStatusService->addStatus('Job gestartet für Prompt.');

        $messages = new MessageBag(Message::ofUser($prompt));

        $attempt = 1;
        $lastError = null;
        $result = null;
        $startTime = time();

        while ($attempt <= self::MAX_RETRIES) {
            // safety total time check
            if ((time() - $startTime) > self::MAX_TOTAL_SECONDS) {
                $this->agentStatusService->addStatus('Maximale Gesamtlaufzeit für Retries überschritten.');
                $this->logger->error('Max total retry time exceeded', ['elapsed' => time() - $startTime]);
                break;
            }

            try {
                $this->agentStatusService->addStatus(sprintf('AI-Agent wird aufgerufen (Versuch %d/%d).', $attempt, self::MAX_RETRIES));
                $result = $this->agent->call($messages);
                $this->agentStatusService->addStatus('Antwort vom AI-Agent erhalten.');

                // Zusätzliche Prüfung: Ergebnis darf nicht leer sein
                $content = $this->extractContentSafely($result);
                if ($content === '') {
                    throw new \RuntimeException('Response does not contain any content.');
                }

                // Erfolg: Breche die Schleife ab
                break;

            } catch (Throwable $e) {
                $lastError = $e;
                $errorMessage = $e->getMessage() ?? get_class($e);
                $isRetriable = false;

                // 1. Standardmäßige retriable HTTP-Fehler (5xx)
                if ($e instanceof ServerExceptionInterface) {
                    $isRetriable = true;
                }
                // 2. Transport-/Verbindungsfehler
                else if ($e instanceof TransportExceptionInterface) {
                    $isRetriable = true;
                }
                // 3. API-Textchecks für typische transient messages
                else if (
                    stripos($errorMessage, '503') !== false ||
                    stripos($errorMessage, 'UNAVAILABLE') !== false ||
                    stripos($errorMessage, 'overloaded') !== false ||
                    stripos($errorMessage, 'timed out') !== false ||
                    stripos($errorMessage, 'timeout') !== false
                ) {
                    $isRetriable = true;
                }
                // 4. Leerer Body / Parser-Meldungen
                else if (stripos($errorMessage, 'Response does not contain any content') !== false ||
                         stripos($errorMessage, 'Invalid JSON') !== false ||
                         stripos($errorMessage, 'Code execution failed') !== false) {
                    // manche "Code execution failed" können transient sein; wir versuchen nochmal
                    $isRetriable = true;
                }

                // Wenn retriable und noch Versuche übrig: Backoff mit Jitter
                if ($isRetriable && $attempt < self::MAX_RETRIES) {
                    $backoff = $this->computeBackoff($attempt);
                    $this->logger->warning('Retriable Fehler beim Agent-Aufruf erkannt. Warte und versuche erneut.', [
                        'attempt' => $attempt,
                        'error_type' => $e::class,
                        'message' => $errorMessage,
                        'backoff_seconds' => $backoff,
                    ]);

                    $this->agentStatusService->addStatus(sprintf(
                        'Fehler (Retriable) erkannt: %s. Warte %d Sek. vor erneutem Versuch.',
                        $this->shortPreview($errorMessage),
                        $backoff
                    ));

                    // blockierendes sleep ist ok in CLI/worker; in HTTP-requests lieber asynchrone retry-Mechanik
                    sleep($backoff);
                    $attempt++;
                    continue;
                }

                // Nicht retriable Fehler oder letzter Versuch fehlgeschlagen
                $this->logger->error('Fehler im AI-Agent-Service (Nicht-Retriable oder Max Retries erreicht)', [
                    'message' => $errorMessage,
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt,
                ]);

                $this->agentStatusService->addStatus(sprintf(
                    'Fehler beim Ausführen des AI-Agenten (Versuch %d): %s. Keine weiteren Retries.',
                    $attempt,
                    $this->shortPreview($errorMessage)
                ));

                // Optional: persist detailed error payload for post-mortem (implement as needed)
                return;
            }
        }

        // Ergebnisverarbeitung nur, wenn $result gesetzt wurde (Erfolgreicher Durchlauf)
        if ($result !== null) {
            $content = $this->extractContentSafely($result);

            if ($content === '') {
                $this->agentStatusService->addStatus('Agent-Antwort war leer, Verarbeitung abgebrochen.');
                $this->logger->error('Agent returned empty content after successful call.');
                return;
            }

            $this->logger->info('Agent erfolgreich ausgeführt', ['content_preview' => substr($content, 0, 200)]);
            $this->agentStatusService->addStatus('AI-Agent erfolgreich abgeschlossen.');

            // Hier würde die Logik zur Dateiverarbeitung/Deployment folgen.
            // Beispiel: $this->deployTool->deployFromString($content);
            return;
        }

        // Kein Erfolg nach allen Retries
        $this->agentStatusService->addStatus(sprintf('Alle %d Versuche zur Ausführung des AI-Agenten sind fehlgeschlagen.', self::MAX_RETRIES));
        $this->logger->error('All retries exhausted for AiAgentService', [
            'max_retries' => self::MAX_RETRIES,
            'last_error' => $lastError ? $lastError->getMessage() : null,
        ]);
    }

    private function computeBackoff(int $attempt): int
    {
        // Exponentielles Backoff mit Full jitter
        $expo = min(self::BASE_DELAY_SECONDS * (2 ** ($attempt - 1)), self::MAX_BACKOFF_SECONDS);
        // Full jitter: random zwischen 0 und expo
        return (int) round(mt_rand(0, 1000) / 1000 * $expo);
    }

    private function extractContentSafely(mixed $result): string
    {
        // Wenn Result ein Response-Objekt oder ein spezifisches Agent-Result ist, adaptieren Sie diese Methode.
        if (is_object($result)) {
            // versuchen gängige Methoden
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
            return json_encode($result);
        }

        // scalar or null
        return is_scalar($result) ? (string) $result : ($result === null ? '' : json_encode($result));
    }

    private function shortPreview(string $text, int $len = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $text));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }
}
