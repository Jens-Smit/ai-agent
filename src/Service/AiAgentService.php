<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use App\Service\AgentStatusService;
use App\Tool\DeployGeneratedCodeTool;
// Imports für Retry-Logik hinzufügen
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class AiAgentService
{
    private const MAX_RETRIES = 5;
    private const RETRY_DELAY_SECONDS = 60;

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

        while ($attempt <= self::MAX_RETRIES) {
            try {
                $this->agentStatusService->addStatus(sprintf('AI-Agent wird aufgerufen (Versuch %d/%d).', $attempt, self::MAX_RETRIES));
                $result = $this->agent->call($messages);
                $this->agentStatusService->addStatus('Antwort vom AI-Agent erhalten.');
                
                // Erfolg: Breche die Schleife ab
                break; 

            } catch (\Throwable $e) {
                $lastError = $e;
                $errorMessage = $e->getMessage();
                $isRetriable = false;

                // 1. Prüfen auf standardmäßige retriable HTTP-Fehler (5xx)
                if ($e instanceof ServerExceptionInterface) {
                    $isRetriable = true;
                }
                // 2. Prüfen auf Transport-/Verbindungsfehler
                else if ($e instanceof TransportExceptionInterface) {
                    $isRetriable = true;
                }
                // 3. Prüfen auf 503/UNAVAILABLE Fehler
                else if (str_contains($errorMessage, '503') || str_contains($errorMessage, 'UNAVAILABLE') || str_contains($errorMessage, 'overloaded')) {
                    $isRetriable = true;
                }
                // 4. NEU: Prüfen auf 'Response does not contain any content.' Fehler
                else if (str_contains($errorMessage, 'Response does not contain any content.')) {
                    $isRetriable = true;
                }

                if ($isRetriable && $attempt < self::MAX_RETRIES) {
                    $this->logger->warning('Retriable Fehler beim Agent-Aufruf erkannt. Warte und versuche erneut.', [
                        'attempt' => $attempt,
                        'error_type' => $e::class,
                        'message' => $errorMessage,
                    ]);
                    
                    $this->agentStatusService->addStatus(sprintf('Fehler (Retriable) erkannt. Warte %d Sek. vor erneutem Versuch.', self::RETRY_DELAY_SECONDS));
                    sleep(self::RETRY_DELAY_SECONDS); 
                    $attempt++;
                    continue; // Gehe zum nächsten Schleifendurchlauf
                }

                // Nicht retriable Fehler oder letzter Versuch fehlgeschlagen
                $this->logger->error('Fehler im AI-Agent-Service (Nicht-Retriable oder Max Retries erreicht)', [
                    'message' => $errorMessage,
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Fehler beim Ausführen des AI-Agenten (Versuch %d). Keine weiteren Retries.', $attempt));
                
                // Beende die Ausführung, da der Fehler nicht behoben werden konnte
                return;
            }
        }

        // Ergebnisverarbeitung nur, wenn $result gesetzt wurde (Erfolgreicher Durchlauf)
        if ($result) {
            $content = method_exists($result, 'getContent') ? $result->getContent() : (string) $result;

            $this->logger->info('Agent erfolgreich ausgeführt', ['content' => $content]);
            $this->agentStatusService->addStatus('AI-Agent erfolgreich abgeschlossen.');

            // Hier würde die Logik zur Dateiverarbeitung/Deployment folgen.
        } else {
             $this->agentStatusService->addStatus(sprintf('Alle %d Versuche zur Ausführung des AI-Agenten sind fehlgeschlagen.', self::MAX_RETRIES));
        }
    }
}