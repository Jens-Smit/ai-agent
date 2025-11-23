<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use App\Tool\WebScraperTool; 
use App\Tool\GoogleSearchTool; // Import der tatsächlichen Such-Klasse

#[AsTool(
    name: 'information_extractor',
    description: 'Führt eine allgemeine Google-Suche durch, besucht die besten Suchergebnisse und extrahiert den Haupttext (Absätze und Überschriften) von diesen Seiten, um die angefragten Informationen zu finden.'
)]
final class InformationExtractorTool
{
    private WebScraperTool $webScraper;
    private GoogleSearchTool $searchTool; 
    private LoggerInterface $logger;
    
    // Definiert die maximale Anzahl von URLs, die gescrapet werden sollen, um die Ausführungszeit zu begrenzen
    private const MAX_URLS_TO_SCRAPE = 3;

    public function __construct(
        WebScraperTool $webScraper,
        GoogleSearchTool $searchTool,
        LoggerInterface $logger
    ) {
        $this->webScraper = $webScraper;
        $this->searchTool = $searchTool;
        $this->logger = $logger;
    }

    /**
     * Sucht im Web nach einer Abfrage, scrapt die Top-Ergebnisse und extrahiert den Inhalt.
     *
     * @param string $query Die Suchabfrage, nach der Informationen gefunden werden sollen.
     * @return array Ein JSON-Objekt mit der Liste der besuchten URLs und dem gescrapten Inhalt.
     */
    public function __invoke(
        string $query
    ): array {
        $this->logger->info(sprintf('Starte allgemeine Informationssuche für: %s', $query));
        $result = [
            'query' => $query,
            'results' => [],
            'error' => null,
        ];

        // 1. Führe die Google-Suche durch
        $searchResults = ($this->searchTool)($query);
        $potentialUrls = array_values($searchResults);

        if (empty($potentialUrls)) {
            $result['error'] = sprintf('Keine Webseiten für die Abfrage "%s" gefunden.', $query);
            $this->logger->warning($result['error']);
            return $result;
        }

        // 2. Wähle die besten URLs zum Scrapen aus
        $urlsToScrape = array_slice($potentialUrls, 0, self::MAX_URLS_TO_SCRAPE);
        
        $this->logger->info(sprintf('Scrape die folgenden URLs: %s', implode(', ', $urlsToScrape)));

        // 3. Definiere generische Selektoren zum Extrahieren von Hauptinhalten
        $selectors = [
            'title' => 'head title::text', 
            'h1' => 'h1::text',           
            'h2' => 'h2::text',           
            'paragraphs' => 'p::text',    
        ];

        foreach ($urlsToScrape as $url) {
            try {
                // Scrape die URL
                $scrapeResult = ($this->webScraper)($url, $selectors);
                
                $data = $scrapeResult['data'] ?? [];
                
                // Aggregiere den gescrapten Text (überspringe leere Ergebnisse)
                // Filtert leere oder null-Werte aus und kombiniert alle Texte
                $content = array_filter(array_merge(
                    (array)($data['title'] ?? []),
                    (array)($data['h1'] ?? []),
                    (array)($data['h2'] ?? []),
                    (array)($data['paragraphs'] ?? [])
                ));

                // Speichere das Ergebnis
                $result['results'][] = [
                    'url' => $url,
                    // Fügt einen kurzen Ausschnitt für das Logging und die Übersicht hinzu
                    'content_snippet' => implode(' ', array_slice($content, 0, 10)) . '...', 
                    // Vollständiger Inhalt für die KI-Synthese
                    'full_content' => $content, 
                ];

                $this->logger->info(sprintf('Erfolgreich gescraped: %s', $url));

            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Fehler beim Scrapen von %s: %s', $url, $e->getMessage()));
                $result['results'][] = [
                    'url' => $url,
                    'error' => 'Scraping-Fehler: ' . $e->getMessage(),
                ];
            }
        }

        $this->logger->info('Informations-Extraktion abgeschlossen.', ['result_count' => count($result['results'])]);
        
        return $result;
    }
}