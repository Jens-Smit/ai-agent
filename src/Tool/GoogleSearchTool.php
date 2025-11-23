<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
// #[With] wird nur für JSON Schema Regeln verwendet, nicht für die Beschreibung
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With; 
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTool(
    name: 'google_search',
    description: 'Führt eine Websuche mit der Google Custom Search API durch und gibt die Top-Suchergebnisse (Titel und URL) für eine gegebene Anfrage zurück.'
)]
final class GoogleSearchTool
{
    private const API_ENDPOINT = 'https://www.googleapis.com/customsearch/v1';

    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $searchEngineCx;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        // Autowire für Umgebungsvariablen bleibt korrekt
        
        string $apiKey,
       
        string $searchEngineCx
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->searchEngineCx = $searchEngineCx;
    }

    /**
     * Führt eine Google-Suche durch und gibt die Top-Ergebnisse zurück.
     *
     * @param string $query Der Suchbegriff (z.B. "SAP Karriere"). <-- HIER GEHÖRT DIE BESCHREIBUNG HIN
     * @return array<string, string> Ein assoziatives Array von Suchergebnissen (Titel => URL).
     */
    public function __invoke(
        // #[With] wird hier entfernt, da wir keine Validierungsregeln benötigen.
        string $query
    ): array {
        $this->logger->info(sprintf('Starte Google Custom Search für Anfrage: %s', $query));
        $results = [];

        try {
            // ... (Rest der Logik bleibt unverändert)
            $response = $this->httpClient->request('GET', self::API_ENDPOINT, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->searchEngineCx,
                    'q' => $query,
                    'num' => 5, // Limitiere auf die Top 5 Ergebnisse
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $errorMessage = sprintf('Google Search API Fehler. Status Code: %d', $statusCode);
                $this->logger->error($errorMessage, ['status_code' => $statusCode, 'response' => $response->getContent(false)]);
                return ['error' => $errorMessage];
            }

            $content = $response->toArray();

            // Verarbeite die Ergebnisse aus dem 'items' Array
            if (isset($content['items'])) {
                foreach ($content['items'] as $item) {
                    if (isset($item['title']) && isset($item['link'])) {
                        // Füge Titel => URL zum Ergebnis-Array hinzu
                        $results[$item['title']] = $item['link'];
                    }
                }
            }
            
            $this->logger->info('Google-Suche erfolgreich abgeschlossen.', ['count' => count($results)]);

        } catch (\Exception $e) {
            $errorMessage = sprintf('Fehler beim Aufruf der Google Search API: %s', $e->getMessage());
            $this->logger->error($errorMessage, ['exception' => $e->getMessage()]);
            return ['error' => $errorMessage];
        }

        return $results;
    }
}