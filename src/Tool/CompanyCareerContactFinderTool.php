<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use App\Tool\WebScraperTool; 
use App\Tool\GoogleSearchTool; 

/**
 * Durchsucht das Web für einen gegebenen Firmennamen und versucht, 
 * Kontakt- und Bewerbungsdetails zu extrahieren.
 */
#[AsTool(
    name: 'company_career_contact_finder',
    description: 'Durchsucht das Web für einen gegebenen Firmennamen und versucht, allgemeine Kontakt-E-Mail-Adressen, spezifische Bewerbungs-E-Mail-Adressen und Namen von HR- oder Personalmanagern für Bewerbungen zu extrahieren.'
)]
final class CompanyCareerContactFinderTool
{
    private WebScraperTool $webScraper;
    private GoogleSearchTool $searchTool; 
    private LoggerInterface $logger;

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
     * Sucht nach Kontaktinformationen und Bewerbungsdetails für ein Unternehmen.
     *
     * @param string $company_name Der Name des zu recherchierenden Unternehmens.
     * @return array Ein JSON-Objekt mit den extrahierten Kontaktdaten.
     */
    public function __invoke(
        string $company_name
    ): array {
        // --- VERBESSERTES LOGGING DES INPUTS ---
        $this->logger->info(sprintf('TOOL-INPUT: Starte Suche nach Kontaktdaten für Unternehmen: "%s"', $company_name));
        
        $result = [
            'company_name' => $company_name,
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
            'career_page_url' => null,
        ];

        // 1. Finde die Hauptseite und die Karriere-Seite
        $searchQuery = sprintf('%s Karriere Kontakt E-Mail', $company_name); // Präzisere Suche
        $this->logger->debug(sprintf('Führe Google Search mit Query aus: "%s"', $searchQuery));
        $searchResults = ($this->searchTool)($searchQuery);
        $potentialUrls = array_values($searchResults);

        if (empty($potentialUrls)) {
            $this->logger->warning(sprintf('TOOL-WARN: Keine relevanten Webseiten für %s gefunden.', $company_name));
            // --- VERBESSERTES LOGGING DES OUTPUTS IM FEHLERFALL ---
            $this->logger->info('TOOL-OUTPUT: Suche abgeschlossen mit leeren Resultaten.', ['result' => $result]);
            return $result;
        }

        // Wir nehmen die erste gefundene URL als die wahrscheinlichste Karriere- oder Hauptseite.
        $careerUrl = $potentialUrls[0];
        $result['career_page_url'] = $careerUrl;
        $this->logger->info(sprintf('TOOL-INFO: Potentielle Karriere-URL identifiziert: %s', $careerUrl));

        // 2. Extrahiere E-Mails und Ansprechpartner von der Seite
        $selectors = [
            'emails' => 'a[href^="mailto"]::href', 
            'contact_text_1' => '.contact-info, .footer-contact, .imprint, .datenschutz, .career-contact::text',
            'person_name' => '.contact-person-name, .hr-contact h4, .bewerbung-ansprechpartner::text',
        ];

        $this->logger->debug(sprintf('Starte WebScraper für URL: %s mit %d Selektoren', $careerUrl, count($selectors)));
        $scrapeResult = ($this->webScraper)($careerUrl, $selectors);

        if (isset($scrapeResult['data'])) {
            $data = $scrapeResult['data'];
            $this->logger->debug('Scraper-Daten erfolgreich abgerufen und werden analysiert.');

            // E-Mail-Extraktion
            $allEmails = array_merge((array)$data['emails'], (array)$data['contact_text_1']);
            foreach ($allEmails as $item) {
                if (is_string($item)) {
                    // Generischer E-Mail-Regex-Match
                    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i', $item, $matches)) {
                        foreach ($matches[0] as $email) {
                            if (str_contains(strtolower($email), 'bewerbung') || str_contains(strtolower($email), 'application') || str_contains(strtolower($email), 'hr')) {
                                $result['application_email'] = $email;
                                $this->logger->info(sprintf('TOOL-FOUND: Spezifische Bewerbungs-E-Mail gefunden: %s', $email));
                            } elseif ($result['general_email'] === null) {
                                $result['general_email'] = $email;
                                $this->logger->debug(sprintf('TOOL-FOUND: Allgemeine E-Mail gefunden: %s', $email));
                            }
                        }
                    }
                }
            }

            // Ansprechpartner-Extraktion
            $personNames = (array)$data['person_name'];
            foreach ($personNames as $name) {
                $name = trim($name);
                if (!empty($name) && $result['contact_person'] === null) {
                    // Einfache Heuristik zur Erkennung eines Namens
                    if (str_word_count($name) >= 2 && !preg_match('/(impressum|kontakt|datenschutz|agb|copyright)/i', $name)) {
                        $result['contact_person'] = $name;
                        $this->logger->info(sprintf('TOOL-FOUND: Ansprechpartner gefunden: %s', $name));
                        break;
                    }
                }
            }
        } else {
             $this->logger->warning('TOOL-WARN: WebScraper konnte keine Daten von der Seite extrahieren.');
        }

        // --- VERBESSERTES LOGGING DES FINALEN ERGEBNISSES ---
        $this->logger->info('TOOL-OUTPUT: Suche abgeschlossen. Endergebnis:', ['result' => $result]);
        return $result;
    }
}