<?php
declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Ein Service, der es dem AI Agent ermöglicht, die Entwicklung neuer Tools anzufordern.
 */
#[AsTool(
    name: 'request_new_ai_agent_tool',
    description: 'Requests the development agent to create a new Symfony AI Agent tool based on a detailed description.'
)]
final readonly class ToolDefinitionGenerator
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Sendet eine detaillierte Anfrage an den Entwicklungsagenten, um ein neues Symfony AI Agent Tool zu erstellen.
     *
     * @param string $toolName Ein kurzer, beschreibender Name für das neue Tool (z.B. "weather_forecast", "database_query")
     * @param string $toolDescription Eine umfassende natürlichsprachliche Beschreibung des zu entwickelnden Tools.
     *                                Dies sollte enthalten:
     *                                - Den Hauptzweck des Tools
     *                                - Alle erforderlichen Parameter (Name, Typ, Beschreibung)
     *                                - Den erwarteten Rückgabetyp und das Format
     *                                - Spezifische Einschränkungen oder Verhaltensweisen
     * @return string Eine Bestätigungsnachricht, die anzeigt, ob die Tool-Entwicklungsanfrage erfolgreich gesendet wurde
     */
    public function __invoke(string $toolName, string $toolDescription): string
    {
        $promptForDevAgent = sprintf(
            'Entwickle ein neues Symfony AI Agent Tool namens "%s" mit folgender Funktionalität: %s. ' .
            'Stelle sicher, dass das Tool allen Symfony AI Agent Tool-Konventionen folgt, einschließlich des #[AsTool] Attributs, ' .
            'typisierter Parameter, umfassender PHPDoc-Kommentare und geeigneter Rückgabetypen. ' .
            'Falls das Tool externe Abhängigkeiten benötigt (z.B. HTTP-Clients, spezifische Services), ' .
            'stelle sicher, dass diese ordnungsgemäß über den Konstruktor injiziert und als Symfony Services konfiguriert werden. ' .
            'Generiere auch eine entsprechende PHPUnit-Testdatei mit guter Abdeckung für das neue Tool.',
            $toolName,
            $toolDescription
        );

        try {
            $response = $this->httpClient->request('POST', 'http://localhost/api/devAgent', [
                'json' => [
                    'prompt' => $promptForDevAgent,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info(sprintf('Tool-Entwicklungsanfrage für "%s" erfolgreich gesendet.', $toolName));
                return sprintf(
                    'Tool-Entwicklungsanfrage für "%s" wurde erfolgreich an den Entwicklungsagenten gesendet. ' .
                    'Antwort: %s',
                    $toolName,
                    json_encode($content)
                );
            } else {
                $this->logger->error(sprintf(
                    'Fehler beim Senden der Tool-Entwicklungsanfrage für "%s". Status: %d, Nachricht: %s',
                    $toolName,
                    $statusCode,
                    json_encode($content)
                ));
                return sprintf(
                    'Fehler beim Senden der Tool-Entwicklungsanfrage für "%s". Der Entwicklungsagent antwortete mit einem Fehler (Status: %d). Details: %s',
                    $toolName,
                    $statusCode,
                    json_encode($content)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ein Fehler trat beim Senden der Tool-Entwicklungsanfrage für "%s" auf: %s',
                $toolName,
                $e->getMessage()
            ));
            return sprintf(
                'Ein unerwarteter Fehler trat beim Anfordern der Tool-Entwicklung für "%s" auf: %s',
                $toolName,
                $e->getMessage()
            );
        }
    }
}