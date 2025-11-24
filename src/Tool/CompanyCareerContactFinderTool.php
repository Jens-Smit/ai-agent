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
    private HttpClientInterface $httpClient;

    public function __construct(
        WebScraperTool $webScraper,
        GoogleSearchTool $searchTool,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->webScraper = $webScraper;
        $this->searchTool = $searchTool;
        $this->httpClient = $httpClient;
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
        $this->logger->info(sprintf('TOOL-INPUT: Starte Suche nach Kontaktdaten für Unternehmen: "%s"', $company_name));
        
        $result = [
            'company_name' => $company_name,
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
            'career_page_url' => null,
            'success' => false,
        ];

        // 1. Finde die Hauptseite und die Karriere-Seite
        $searchQuery = sprintf('%s Karriere Bewerbung Kontakt', $company_name);
        $this->logger->debug(sprintf('Führe Google Search mit Query aus: "%s"', $searchQuery));
        $searchResults = ($this->searchTool)($searchQuery);
        $potentialUrls = array_values($searchResults);

        if (empty($potentialUrls)) {
            $this->logger->warning(sprintf('TOOL-WARN: Keine relevanten Webseiten für %s gefunden.', $company_name));
            $this->logger->info('TOOL-OUTPUT: Suche abgeschlossen mit leeren Resultaten.', ['result' => $result]);
            return $result;
        }

        // Durchsuche die Top 3 URLs für bessere Trefferquote
        $urlsToCheck = array_slice($potentialUrls, 0, 3);
        
        foreach ($urlsToCheck as $index => $url) {
            $this->logger->info(sprintf('TOOL-INFO: Prüfe URL #%d: %s', $index + 1, $url));
            
            try {
                // Hole den kompletten HTML-Content
                $htmlContent = $this->fetchHtmlContent($url);
                
                if ($htmlContent === null) {
                    $this->logger->warning(sprintf('Konnte HTML-Content von %s nicht abrufen', $url));
                    continue;
                }
                
                // Extrahiere E-Mails aus dem gesamten HTML-Text
                $extractedEmails = $this->extractEmailsFromText($htmlContent);
                
                foreach ($extractedEmails as $email) {
                    $emailLower = strtolower($email);
                    
                    // Priorisiere Bewerbungs-E-Mails
                    if (str_contains($emailLower, 'bewerbung') || 
                        str_contains($emailLower, 'application') || 
                        str_contains($emailLower, 'karriere') ||
                        str_contains($emailLower, 'career') ||
                        str_contains($emailLower, 'hr') ||
                        str_contains($emailLower, 'jobs')) {
                        
                        if ($result['application_email'] === null) {
                            $result['application_email'] = $email;
                            $result['career_page_url'] = $url;
                            $result['success'] = true;
                            $this->logger->info(sprintf('TOOL-FOUND: Bewerbungs-E-Mail gefunden: %s auf %s', $email, $url));
                        }
                    } elseif ($result['general_email'] === null && 
                              !str_contains($emailLower, 'noreply') &&
                              !str_contains($emailLower, 'no-reply')) {
                        $result['general_email'] = $email;
                        $result['success'] = true; // Auch allgemeine E-Mail ist ein Erfolg
                        $this->logger->debug(sprintf('TOOL-FOUND: Allgemeine E-Mail gefunden: %s', $email));
                    }
                }
                
                // Versuche auch strukturiert mit WebScraper
                $scrapeResult = $this->scrapeWithSelectors($url);
                
                // Merge die Ergebnisse
                if ($scrapeResult['application_email'] && $result['application_email'] === null) {
                    $result['application_email'] = $scrapeResult['application_email'];
                    $result['success'] = true;
                }
                if ($scrapeResult['general_email'] && $result['general_email'] === null) {
                    $result['general_email'] = $scrapeResult['general_email'];
                    $result['success'] = true;
                }
                if ($scrapeResult['contact_person'] && $result['contact_person'] === null) {
                    $result['contact_person'] = $scrapeResult['contact_person'];
                }
                
                // Wenn wir eine Bewerbungs-E-Mail gefunden haben, können wir aufhören
                if ($result['application_email'] !== null) {
                    break;
                }
                
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Fehler beim Verarbeiten von %s: %s', $url, $e->getMessage()));
                continue;
            }
        }

        // Wenn keine career_page_url gesetzt wurde, aber Daten gefunden wurden, setze die erste URL
        if ($result['success'] && $result['career_page_url'] === null && !empty($potentialUrls)) {
            $result['career_page_url'] = $potentialUrls[0];
        }

        $this->logger->info('TOOL-OUTPUT: Suche abgeschlossen. Endergebnis:', ['result' => $result]);
        return $result;
    }
    
    /**
     * Holt den HTML-Content einer URL
     */
    private function fetchHtmlContent(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                ],
            ]);
            
            if ($response->getStatusCode() >= 400) {
                return null;
            }
            
            return $response->getContent();
            
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Fehler beim Abrufen von %s: %s', $url, $e->getMessage()));
            return null;
        }
    }
    
    /**
     * Extrahiert alle E-Mail-Adressen aus einem Text
     */
    private function extractEmailsFromText(string $text): array
    {
        $emails = [];
        
        // Umfassender E-Mail-Regex
        $pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        
        if (preg_match_all($pattern, $text, $matches)) {
            $emails = array_unique($matches[0]);
            
            // Filtere offensichtlich ungültige E-Mails
            $emails = array_filter($emails, function($email) {
                $emailLower = strtolower($email);
                
                // Blacklist ungültiger Domains/Muster
                $blacklist = [
                    'example.com', 'domain.com', 'test@', 'email@', 
                    '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp',
                    'user@', 'name@', 'your@', 'company@'
                ];
                
                foreach ($blacklist as $blocked) {
                    if (str_contains($emailLower, $blocked)) {
                        return false;
                    }
                }
                
                return true;
            });
        }
        
        return array_values($emails);
    }
    
    /**
     * Scraped mit spezifischen Selektoren (Fallback-Methode)
     */
    private function scrapeWithSelectors(string $url): array
    {
        $result = [
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
        ];
        
        // Erweiterte Selektoren für verschiedene Website-Strukturen
        $selectors = [
            'mailto_links' => 'a[href^="mailto"]::href',
            'email_paragraphs' => 'p, div, span, li::text',
            'contact_headings' => 'h1, h2, h3, h4, h5, h6::text',
            'person_elements' => '.name, .contact-name, .author, .staff-name::text',
        ];
        
        $scrapeResult = ($this->webScraper)($url, $selectors);
        
        if (isset($scrapeResult['data'])) {
            $data = $scrapeResult['data'];
            
            // E-Mails aus mailto-Links
            if (isset($data['mailto_links'])) {
                $mailtoLinks = is_array($data['mailto_links']) ? $data['mailto_links'] : [$data['mailto_links']];
                
                foreach ($mailtoLinks as $mailto) {
                    if (is_string($mailto) && str_starts_with($mailto, 'mailto:')) {
                        $email = str_replace('mailto:', '', $mailto);
                        $email = explode('?', $email)[0]; // Entferne Query-Parameter
                        $email = trim($email);
                        
                        $emailLower = strtolower($email);
                        if (str_contains($emailLower, 'bewerbung') || 
                            str_contains($emailLower, 'application') ||
                            str_contains($emailLower, 'karriere') ||
                            str_contains($emailLower, 'hr')) {
                            $result['application_email'] = $email;
                        } elseif ($result['general_email'] === null) {
                            $result['general_email'] = $email;
                        }
                    }
                }
            }
            
            // E-Mails aus Text-Paragraphen
            if (isset($data['email_paragraphs'])) {
                $paragraphs = is_array($data['email_paragraphs']) ? $data['email_paragraphs'] : [$data['email_paragraphs']];
                
                foreach ($paragraphs as $paragraph) {
                    if (is_string($paragraph)) {
                        $foundEmails = $this->extractEmailsFromText($paragraph);
                        
                        foreach ($foundEmails as $email) {
                            $emailLower = strtolower($email);
                            if (str_contains($emailLower, 'bewerbung') || 
                                str_contains($emailLower, 'application') ||
                                str_contains($emailLower, 'karriere') ||
                                str_contains($emailLower, 'hr')) {
                                
                                if ($result['application_email'] === null) {
                                    $result['application_email'] = $email;
                                }
                            } elseif ($result['general_email'] === null) {
                                $result['general_email'] = $email;
                            }
                        }
                    }
                }
            }
            
            // Ansprechpartner-Erkennung - VERBESSERT
            if (isset($data['person_elements'])) {
                $persons = is_array($data['person_elements']) ? $data['person_elements'] : [$data['person_elements']];
                
                foreach ($persons as $name) {
                    $name = trim($name);
                    if ($this->isValidPersonName($name)) {
                        $result['contact_person'] = $name;
                        break;
                    }
                }
            }
            
            // Fallback: Suche in Überschriften nach Personennamen
            if ($result['contact_person'] === null && isset($data['contact_headings'])) {
                $headings = is_array($data['contact_headings']) ? $data['contact_headings'] : [$data['contact_headings']];
                
                foreach ($headings as $heading) {
                    $heading = trim($heading);
                    if ($this->isValidPersonName($heading)) {
                        $result['contact_person'] = $heading;
                        break;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Prüft, ob ein String ein valider Personenname ist
     */
    private function isValidPersonName(string $name): bool
    {
        // Muss mindestens 2 Wörter haben (Vor- und Nachname)
        if (str_word_count($name) < 2) {
            return false;
        }
        
        // Nicht mehr als 5 Wörter (sonst wahrscheinlich ein Satz)
        if (str_word_count($name) > 5) {
            return false;
        }
        
        // Nicht zu lang (max 60 Zeichen)
        if (strlen($name) > 60) {
            return false;
        }
        
        // Blacklist für häufige Nicht-Namen
        $blacklist = [
            'impressum', 'kontakt', 'datenschutz', 'agb', 'copyright', 
            'karriere', 'jobs', 'bewerbung', 'stellenangebote',
            'für nachunternehmer', 'für lieferanten', 'für bewerber',
            'unser team', 'ihre ansprechpartner', 'das team',
            'kontaktieren sie', 'schreiben sie', 'rufen sie',
            'mehr informationen', 'weitere infos',
        ];
        
        $nameLower = strtolower($name);
        foreach ($blacklist as $blocked) {
            if (str_contains($nameLower, $blocked)) {
                return false;
            }
        }
        
        // Muss Großbuchstaben enthalten (typisch für Namen)
        if (!preg_match('/[A-ZÄÖÜ]/', $name)) {
            return false;
        }
        
        // Darf nicht nur Großbuchstaben sein (wahrscheinlich Überschrift)
        if ($name === strtoupper($name)) {
            return false;
        }
        
        return true;
    }
}