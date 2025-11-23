<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use App\Tool\WebScraperTool; // Das vorausgesetzte WebScraperTool
use App\Tool\GoogleSearchTool; // Das vorausgesetzte Such-Tool

/**
 * Anzunehmendes Such-Tool für die erste URL-Ermittlung.
 * Dies ist ein Platzhalter, da die direkte Web-Suche außerhalb der
 * bereitgestellten Tools liegt.
 */


#[AsTool(
    name: 'company_career_contact_finder',
    description: 'Durchsucht das Web für einen gegebenen Firmennamen und versucht, allgemeine Kontakt-E-Mail-Adressen, spezifische Bewerbungs-E-Mail-Adressen und Namen von HR- oder Personalmanagern für Bewerbungen zu extrahieren.'
)]
final class CompanyCareerContactFinderTool
{
    private WebScraperTool $webScraper;
    private GoogleSearchTool $searchTool; // Angenommenes Such-Tool
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
        $this->logger->info(sprintf('Starte Suche nach Kontaktdaten für: %s', $company_name));
        $result = [
            'company_name' => $company_name,
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
            'career_page_url' => null,
        ];

        // 1. Finde die Hauptseite und die Karriere-Seite
        $searchQuery = sprintf('%s Karriere', $company_name);
        $searchResults = ($this->searchTool)($searchQuery);
        $potentialUrls = array_values($searchResults);

        if (empty($potentialUrls)) {
            $this->logger->warning(sprintf('Keine Webseiten für %s gefunden.', $company_name));
            return $result;
        }

        // Wir nehmen die erste gefundene URL als die wahrscheinlichste Karriere- oder Hauptseite.
        $careerUrl = $potentialUrls[0];
        $result['career_page_url'] = $careerUrl;
        $this->logger->info(sprintf('Potentielle Karriere-URL: %s', $careerUrl));

        // 2. Extrahiere E-Mails und Ansprechpartner von der Seite
        // Wir verwenden einen generischen E-Mail-Regex, um Links (mailto) und Text zu finden.
        // Das WebScraperTool kann das nicht direkt, daher müssen wir uns auf
        // die Text-Extraktion verlassen, oder die Logik erweitern.
        // FÜR DIESES BEISPIEL NEHMEN WIR AN, dass das KI-Modell weiß, dass es
        // das WebScraperTool mit spezifischen CSS-Selektoren für die Suche nach 'mailto:'-Links
        // oder Textfeldern verwenden muss.

        // Realistische Selektoren für das WebScraperTool sind schwer vorherzusagen.
        // Wir setzen auf eine Strategie, die Links zum Impressum, zur Kontaktseite
        // oder direkt nach E-Mails im Footer/Header sucht.

        // VERSUCH 1: E-Mails und Ansprechpartner auf der Karriere-Seite finden
        $selectors = [
            // Versuche, E-Mail-Links zu finden
            'emails' => 'a[href^="mailto"]::href', 
            // Versuche, allgemeine Kontakt-E-Mail-Adressen in spezifischen Containern
            'contact_text_1' => '.contact-info, .footer-contact, .imprint, .datenschutz::text',
            // Versuche, HR- oder Ansprechpartner-Namen zu finden (z.B. in Kontaktboxen)
            'person_name' => '.contact-person-name, .hr-contact h4, .bewerbung-ansprechpartner::text',
        ];

        $scrapeResult = ($this->webScraper)($careerUrl, $selectors);

        if (isset($scrapeResult['data'])) {
            $data = $scrapeResult['data'];

            // E-Mail-Extraktion
            $allEmails = array_merge((array)$data['emails'], (array)$data['contact_text_1']);
            foreach ($allEmails as $item) {
                if (is_string($item)) {
                    // Generischer E-Mail-Regex-Match (muss außerhalb des Scrapers passieren)
                    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i', $item, $matches)) {
                        foreach ($matches[0] as $email) {
                            if (str_contains(strtolower($email), 'bewerbung') || str_contains(strtolower($email), 'application')) {
                                $result['application_email'] = $email;
                            } elseif ($result['general_email'] === null) {
                                $result['general_email'] = $email;
                            }
                        }
                    }
                }
            }

            // Ansprechpartner-Extraktion
            $personNames = (array)$data['person_name'];
            foreach ($personNames as $name) {
                if ($name !== null) {
                    // Einfache Heuristik zur Erkennung eines Namens (kann komplexer sein)
                    if (str_word_count($name) >= 2) {
                        $result['contact_person'] = trim($name);
                        break;
                    }
                }
            }
        }

        // Logik zur Priorisierung und Fallback:
        // Wenn noch keine Bewerbungs-E-Mail gefunden, aber eine allgemeine, könnte man
        // weitere Seiten (Kontakt, Impressum) suchen, aber das würde weitere
        // Such- und Scraping-Aufrufe erfordern.

        $this->logger->info('Suche abgeschlossen.', ['result' => $result]);
        return $result;
    }
}