<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
// WICHTIG: Die Klassen WebScraperTool und GoogleSearchTool müssen existieren
// und in den Abhängigkeiten korrekt aufgelöst werden können.

/**
 * Durchsucht das Web für einen gegebenen Firmennamen und versucht, 
 * Kontakt- und Bewerbungsdetails zu extrahieren.
 *
 * @final
 */
#[AsTool(
    name: 'company_career_contact_finder',
    description: 'Durchsucht das Web für einen gegebenen Firmennamen und versucht, allgemeine Kontakt-E-Mail-Adressen, spezifische Bewerbungs-E-Mail-Adressen und Namen von HR- oder Personalmanagern für Bewerbungen zu extrahieren.'
)]
final class CompanyCareerContactFinderTool
{
    // PHP 8.0+ Constructor Property Promotion für saubere Abhängigkeitsinjektion
    public function __construct(
        private WebScraperTool $webScraper,
        private GoogleSearchTool $searchTool,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Sucht nach Kontaktinformationen und Bewerbungsdetails für ein Unternehmen.
     *
     * @param string $company_name Der Name des zu recherchierenden Unternehmens.
     * @return array<string, mixed> Ein JSON-Objekt mit den extrahierten Kontaktdaten.
     */
    #[With([
        'company_name' => 'Name der Firma (z.B. "Google")',
    ])]
    public function __invoke(
        string $company_name
    ): array {
        $this->logger->info(sprintf('TOOL-INPUT: Starte Suche nach Kontaktdaten für Unternehmen: "%s"', $company_name));
        
        $result = [
            'company_name' => $company_name,
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
            'career_page_url' => null,
            'success' => false,
        ];

        // 1. Google Search nach relevanten URLs
        $searchQuery = sprintf('%s Karriere Bewerbung Kontakt', $company_name);
        $this->logger->debug(sprintf('Führe Google Search mit Query aus: "%s"', $searchQuery));
        
        // Annahme: GoogleSearchTool gibt ein Array von URLs zurück
        $searchResults = ($this->searchTool)($searchQuery);
        $potentialUrls = array_values($searchResults);

        if (empty($potentialUrls)) {
            $this->logger->warning(sprintf('TOOL-WARN: Keine relevanten Webseiten für %s gefunden.', $company_name));
            return $result;
        }

        // Durchsuche die Top 5 URLs für eine bessere Trefferquote
        $urlsToCheck = array_slice($potentialUrls, 0, 5);
        
        foreach ($urlsToCheck as $url) {
            $this->logger->info(sprintf('TOOL-INFO: Prüfe URL: %s', $url));
            
            // Versuche, alle Details aus der aktuellen URL zu extrahieren
            $extractedDetails = $this->processUrl($url);
            
            // Merging: Nur nicht-null Werte aus extractedDetails aktualisieren den Haupt-Result
            $result = array_merge($result, array_filter($extractedDetails, fn($v) => $v !== null));
            
            // Wenn eine Bewerbungs-E-Mail gefunden wurde, setze die URL und beende die Suche
            if ($result['application_email'] !== null) {
                $result['success'] = true;
                $result['career_page_url'] = $url;
                $this->logger->info(sprintf('TOOL-FOUND: Erfolgreiche Kontaktdaten (Bewerbung) gefunden. Beende Suche.'));
                return $result;
            }
        }
        
        // Post-Processing: Wenn allgemeine E-Mail oder Ansprechpartner gefunden, ist es ein Erfolg
        if ($result['general_email'] !== null || $result['contact_person'] !== null) {
            $result['success'] = true;
        }

        // Wenn 'success' ist, aber die career_page_url fehlt, setze die erste URL als Fallback
        if ($result['success'] && $result['career_page_url'] === null && !empty($potentialUrls)) {
            $result['career_page_url'] = $potentialUrls[0];
        }

        $this->logger->info('TOOL-OUTPUT: Suche abgeschlossen. Endergebnis:', ['result' => $result]);
        return $result;
    }
    
    /**
     * Verarbeitet eine einzelne URL, extrahiert alle verfügbaren Kontakt-Details.
     * * @param string $url Die zu verarbeitende URL.
     * @return array<string, mixed> Teil-Ergebnis-Array.
     */
    private function processUrl(string $url): array
    {
        $details = [
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
        ];

        $htmlContent = $this->fetchHtmlContent($url);
        
        if ($htmlContent === null) {
            return $details;
        }
        
        // 1. Extrahiere E-Mails aus dem gesamten HTML-Text (robustester Weg)
        $extractedEmails = $this->extractEmailsFromText($htmlContent);
        
        foreach ($extractedEmails as $email) {
            $this->classifyAndSetEmail($email, $details);
        }

        // 2. Versuche auch strukturiert mit WebScraper (für Ansprechpartner und Mailto-Links)
        $scrapeResult = $this->scrapeWithSelectors($url);

        // E-Mail-Klassifizierung aus Scrape-Resultaten (falls nicht schon im Raw-HTML gefunden)
        if ($scrapeResult['mailto_emails'] ?? null) {
            foreach ($scrapeResult['mailto_emails'] as $email) {
                $this->classifyAndSetEmail($email, $details);
            }
        }
        
        // Ansprechpartner-Erkennung
        if ($details['contact_person'] === null && ($scrapeResult['contact_person'] ?? null)) {
            $details['contact_person'] = $scrapeResult['contact_person'];
        }
        
        return $details;
    }

    /**
     * Klassifiziert eine E-Mail als 'application' oder 'general' und setzt sie im Ergebnis-Array, 
     * sofern noch nicht vorhanden.
     * * @param string $email Die zu klassifizierende E-Mail.
     * @param array<string, mixed> &$details Das Ergebnis-Array, das aktualisiert wird.
     */
    private function classifyAndSetEmail(string $email, array &$details): void
    {
        $emailLower = strtolower($email);

        // Prüfe auf Bewerbungs-E-Mail Keywords
        $isApplicationEmail = (
            str_contains($emailLower, 'bewerbung') || 
            str_contains($emailLower, 'application') || 
            str_contains($emailLower, 'karriere') ||
            str_contains($emailLower, 'career') ||
            str_contains($emailLower, 'hr') ||
            str_contains($emailLower, 'jobs')
        );

        if ($isApplicationEmail && $details['application_email'] === null) {
            $details['application_email'] = $email;
            $this->logger->debug(sprintf('TOOL-FOUND: Bewerbungs-E-Mail gesetzt: %s', $email));
        } elseif ($details['general_email'] === null && 
                  !str_contains($emailLower, 'noreply') && 
                  !str_contains($emailLower, 'no-reply')) {
            // Setze nur, wenn es keine "noreply" Adresse ist und noch keine allgemeine Mail gesetzt wurde
            $details['general_email'] = $email;
            $this->logger->debug(sprintf('TOOL-FOUND: Allgemeine E-Mail gesetzt: %s', $email));
        }
    }

    /**
     * Holt den HTML-Content einer URL unter Verwendung des HTTP-Clients.
     */
    private function fetchHtmlContent(string $url): ?string
    {
        try {
            // Zeitgemäßes Request-Handling mit Timeout und User-Agent
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15, // Reduziertes Timeout
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; CompanyFinderBot/1.0; +https://example.com/bot)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                ],
            ]);
            
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning(sprintf('HTTP-Fehler %d beim Abrufen von %s.', $response->getStatusCode(), $url));
                return null;
            }
            
            return $response->getContent();
            
        } catch (ExceptionInterface $e) { // Fange spezifische HTTP-Client-Exceptions ab
            $this->logger->error(sprintf('Fehler beim Abrufen von %s: %s', $url, $e->getMessage()));
            return null;
        }
    }
    
    /**
     * Extrahiert alle gültigen E-Mail-Adressen aus einem Text.
     */
    private function extractEmailsFromText(string $text): array
    {
        $emails = [];
        // Regex für E-Mail-Adressen, inkl. Blacklist-Filter
        $pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        
        if (preg_match_all($pattern, $text, $matches)) {
            $emails = array_unique($matches[0]);
            
            $emails = array_filter($emails, function(string $email): bool {
                $emailLower = strtolower($email);
                
                // Blacklist ungültiger oder generischer Platzhalter-Muster
                $blacklist = [
                    'example.com', 'domain.com', 'test@', 'email@', 'user@', 'name@',
                    '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', // Ausschluss von Dateiendungen
                    'placeholder', 'mustermann',
                ];
                
                foreach ($blacklist as $blocked) {
                    if (str_contains($emailLower, $blocked)) {
                        return false;
                    }
                }
                
                // Zusätzliche Validierung (könnte in Symfony\Component\Validator ausgelagert werden)
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });
        }
        
        return array_values($emails);
    }
    
    /**
     * Scraped mit spezifischen Selektoren mithilfe des WebScraperTools.
     * * @return array{mailto_emails: string[], contact_person: ?string}
     */
    private function scrapeWithSelectors(string $url): array
    {
        $selectors = [
            'mailto_links' => 'a[href^="mailto"]::href',
            'person_elements' => '.name, .contact-name, .author, .staff-name, [itemprop="name"]::text',
            'contact_headings' => 'h1, h2, h3, h4, h5, h6::text',
        ];
        
        $scrapeResult = ($this->webScraper)($url, $selectors);
        $data = $scrapeResult['data'] ?? [];

        $contactPerson = null;
        $mailtoEmails = [];

        // 1. E-Mails aus mailto-Links extrahieren
        if (isset($data['mailto_links'])) {
            $mailtoLinks = is_array($data['mailto_links']) ? $data['mailto_links'] : [$data['mailto_links']];
            foreach ($mailtoLinks as $mailto) {
                if (is_string($mailto) && str_starts_with($mailto, 'mailto:')) {
                    $email = explode('?', str_replace('mailto:', '', $mailto))[0]; // Entferne Query-Parameter
                    $mailtoEmails[] = trim($email);
                }
            }
        }
        
        // 2. Ansprechpartner-Erkennung aus spezifischen Selektoren
        if (isset($data['person_elements'])) {
            $persons = is_array($data['person_elements']) ? $data['person_elements'] : [$data['person_elements']];
            foreach ($persons as $name) {
                $name = trim($name);
                if ($this->isValidPersonName($name)) {
                    $contactPerson = $name;
                    break;
                }
            }
        }
        
        // 3. Fallback: Suche in Überschriften nach Personennamen
        if ($contactPerson === null && isset($data['contact_headings'])) {
            $headings = is_array($data['contact_headings']) ? $data['contact_headings'] : [$data['contact_headings']];
            foreach ($headings as $heading) {
                $heading = trim($heading);
                if ($this->isValidPersonName($heading)) {
                    $contactPerson = $heading;
                    break;
                }
            }
        }
        
        return [
            'mailto_emails' => array_unique($mailtoEmails),
            'contact_person' => $contactPerson,
        ];
    }
    
    /**
     * Prüft, ob ein String ein valider, relevanter Personenname ist.
     */
    private function isValidPersonName(string $name): bool
    {
        $name = trim($name);

        // Muss mindestens 2 Wörter haben (Vor- und Nachname)
        if (str_word_count($name) < 2) {
            return false;
        }
        
        // Nicht mehr als 5 Wörter (sonst wahrscheinlich ein Satz oder eine Position)
        if (str_word_count($name) > 5) {
            return false;
        }
        
        // Blacklist für häufige Nicht-Namen/Phrasen
        $blacklist = [
            'impressum', 'kontakt', 'datenschutz', 'agb', 'copyright', 
            'karriere', 'jobs', 'bewerbung', 'stellenangebote',
            'unser team', 'ihre ansprechpartner', 'das team',
            'mehr informationen', 'weitere infos',
        ];
        
        $nameLower = strtolower($name);
        foreach ($blacklist as $blocked) {
            if (str_contains($nameLower, $blocked)) {
                return false;
            }
        }
        
        // Muss Großbuchstaben enthalten (typisch für Namen), aber nicht NUR Großbuchstaben (typisch für Überschriften)
        if (!preg_match('/[A-ZÄÖÜ]/', $name) || ($name === strtoupper($name) && strlen($name) > 5)) {
            return false;
        }
        
        return true;
    }
}