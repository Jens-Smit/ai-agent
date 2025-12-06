<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;

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
    public function __construct(
        private WebScraperTool $webScraper,
        private GoogleSearchTool $searchTool,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Sucht nach Kontaktinformationen und Bewerbungsdetails für ein Unternehmen.
     */
    #[With([
        'company_name' => 'Name der Firma (z.B. "Google")',
    ])]
    public function __invoke(string $company_name): array
    {
        $this->logger->info(sprintf('TOOL-INPUT: Starte Suche nach Kontaktdaten für Unternehmen: "%s"', $company_name));
        
        $result = [
            'company_name' => $company_name,
            'general_email' => null,
            'application_email' => null,
            'contact_person' => null,
            'career_page_url' => null,
            'success' => false,
        ];

        // Extrahiere Hauptdomain aus Firmennamen für Domain-Filterung
        $expectedDomain = $this->extractExpectedDomain($company_name);
        $this->logger->debug(sprintf('Erwartete Haupt-Domain: %s', $expectedDomain ?? 'keine erkannt'));

        // Google Search nach relevanten URLs
        $searchQuery = sprintf('%s Karriere Bewerbung Kontakt', $company_name);
        $this->logger->debug(sprintf('Führe Google Search mit Query aus: "%s"', $searchQuery));
        
        $searchResults = ($this->searchTool)($searchQuery);
        $potentialUrls = array_values($searchResults);

        if (empty($potentialUrls)) {
            $this->logger->warning(sprintf('TOOL-WARN: Keine relevanten Webseiten für %s gefunden.', $company_name));
            return $result;
        }

        // Priorisiere URLs: erst relevante der Hauptdomain, dann andere
        $prioritizedUrls = $this->prioritizeUrls($potentialUrls, $expectedDomain, $company_name);
        $urlsToCheck = array_slice($prioritizedUrls, 0, 5);
        
        foreach ($urlsToCheck as $url) {
            $this->logger->info(sprintf('TOOL-INFO: Prüfe URL: %s', $url));
            
            // Domain-Validierung: Überspringe URLs von offensichtlich fremden Domains
            if ($expectedDomain && !$this->isDomainRelevant($url, $expectedDomain, $company_name)) {
                $this->logger->debug(sprintf('TOOL-SKIP: URL gehört nicht zur erwarteten Domain: %s', $url));
                continue;
            }
            
            $extractedDetails = $this->processUrl($url, $company_name);
            
            // Merge nur validierte, nicht-null Werte
            foreach ($extractedDetails as $key => $value) {
                if ($value !== null && $result[$key] === null) {
                    $result[$key] = $value;
                }
            }
            
            // Early Exit: Wenn Bewerbungs-E-Mail gefunden
            if ($result['application_email'] !== null) {
                $result['success'] = true;
                $result['career_page_url'] = $url;
                $this->logger->info('TOOL-FOUND: Bewerbungs-E-Mail gefunden. Beende Suche.');
                return $result;
            }
        }
        
        // Erfolg, wenn mindestens general_email oder contact_person gefunden
        if ($result['general_email'] !== null || $result['contact_person'] !== null) {
            $result['success'] = true;
            
            // Setze career_page_url auf erste relevante URL
            if ($result['career_page_url'] === null && !empty($prioritizedUrls)) {
                $result['career_page_url'] = $prioritizedUrls[0];
            }
        }

        $this->logger->info('TOOL-OUTPUT: Suche abgeschlossen.', ['result' => $result]);
        return $result;
    }
    
    /**
     * Extrahiert erwartete Hauptdomain aus Firmennamen (z.B. "FERCHAU GmbH" -> "ferchau")
     */
    private function extractExpectedDomain(string $companyName): ?string
    {
        // Entferne Rechtsformen und Zusätze
        $cleaned = preg_replace('/\b(GmbH|AG|SE|KG|OHG|mbH|Co\.|Niederlassung|Branch|Inc\.|Ltd\.|LLC)\b/i', '', $companyName);
        $cleaned = trim($cleaned);
        
        // Nimm das erste bedeutende Wort (mind. 3 Zeichen)
        $words = preg_split('/\s+/', $cleaned);
        foreach ($words as $word) {
            $word = strtolower(trim($word));
            if (strlen($word) >= 3 && !in_array($word, ['der', 'die', 'das', 'und', 'für', 'von'])) {
                return $word;
            }
        }
        
        return null;
    }
    
    /**
     * Priorisiert URLs nach Relevanz zur Hauptdomain und Keywords
     */
    private function prioritizeUrls(array $urls, ?string $expectedDomain, string $companyName): array
    {
        usort($urls, function($a, $b) use ($expectedDomain, $companyName) {
            $scoreA = $this->calculateUrlRelevanceScore($a, $expectedDomain, $companyName);
            $scoreB = $this->calculateUrlRelevanceScore($b, $expectedDomain, $companyName);
            return $scoreB <=> $scoreA; // Höhere Scores zuerst
        });
        
        return $urls;
    }
    
    /**
     * Berechnet Relevanz-Score einer URL
     */
    private function calculateUrlRelevanceScore(string $url, ?string $expectedDomain, string $companyName): int
    {
        $score = 0;
        $urlLower = strtolower($url);
        
        // Haupt-Domain-Match (+50 Punkte)
        if ($expectedDomain && str_contains($urlLower, $expectedDomain)) {
            $score += 50;
        }
        
        // Karriere/Bewerbungs-Keywords (+30 Punkte)
        $careerKeywords = ['karriere', 'career', 'jobs', 'bewerbung', 'application', 'stellenangebote'];
        foreach ($careerKeywords as $keyword) {
            if (str_contains($urlLower, $keyword)) {
                $score += 30;
                break;
            }
        }
        
        // Kontakt-Keywords (+20 Punkte)
        $contactKeywords = ['kontakt', 'contact', 'impressum', 'about'];
        foreach ($contactKeywords as $keyword) {
            if (str_contains($urlLower, $keyword)) {
                $score += 20;
                break;
            }
        }
        
        // Niederlassungs-/Locations-Pages (+10 Punkte)
        if (str_contains($urlLower, 'niederlassung') || str_contains($urlLower, 'location') || str_contains($urlLower, 'standort')) {
            $score += 10;
        }
        
        // Penalty für PDFs von fremden Domains (-100 Punkte)
        if (str_ends_with($urlLower, '.pdf')) {
            if (!$expectedDomain || !str_contains($urlLower, $expectedDomain)) {
                $score -= 100;
            }
        }
        
        return $score;
    }
    
    /**
     * Prüft, ob URL zur erwarteten Firma gehört
     */
    private function isDomainRelevant(string $url, string $expectedDomain, string $companyName): bool
    {
        $urlLower = strtolower($url);
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        
        // URL enthält erwartete Domain
        if (str_contains($host, $expectedDomain)) {
            return true;
        }
        
        // Erlaube offizielle Social-Media-/Job-Plattformen
        $allowedPlatforms = ['linkedin.com', 'xing.com', 'indeed.com', 'stepstone.de', 'monster.de'];
        foreach ($allowedPlatforms as $platform) {
            if (str_contains($host, $platform)) {
                return true;
            }
        }
        
        // Blockiere PDFs von fremden Domains (Kataloge, Messen, etc.)
        if (str_ends_with($urlLower, '.pdf') && !str_contains($host, $expectedDomain)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Verarbeitet eine einzelne URL
     */
    private function processUrl(string $url, string $companyName): array
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
        
        // Extrahiere E-Mails aus HTML
        $extractedEmails = $this->extractEmailsFromText($htmlContent);
        
        foreach ($extractedEmails as $email) {
            $this->classifyAndSetEmail($email, $details);
        }

        // Strukturiertes Scraping für Ansprechpartner und mailto-Links
        $scrapeResult = $this->scrapeWithSelectors($url);

        if ($scrapeResult['mailto_emails'] ?? null) {
            foreach ($scrapeResult['mailto_emails'] as $email) {
                $this->classifyAndSetEmail($email, $details);
            }
        }
        
        // Ansprechpartner nur setzen, wenn validiert
        if ($details['contact_person'] === null && ($scrapeResult['contact_person'] ?? null)) {
            $contactPerson = $scrapeResult['contact_person'];
            if ($this->isValidPersonName($contactPerson)) {
                $details['contact_person'] = $contactPerson;
                $this->logger->debug(sprintf('TOOL-FOUND: Ansprechpartner: %s', $contactPerson));
            }
        }
        
        return $details;
    }

    /**
     * Klassifiziert E-Mail als 'application' oder 'general'
     */
    private function classifyAndSetEmail(string $email, array &$details): void
    {
        $emailLower = strtolower($email);

        // Blacklist: Überspringe offensichtlich irrelevante E-Mails
        $blacklist = ['noreply', 'no-reply', 'donotreply', 'mailer-daemon', 'postmaster'];
        foreach ($blacklist as $blocked) {
            if (str_contains($emailLower, $blocked)) {
                return;
            }
        }

        // Bewerbungs-E-Mail-Keywords
        $applicationKeywords = ['bewerbung', 'application', 'karriere', 'career', 'hr', 'jobs', 'recruiting', 'talent'];
        $isApplicationEmail = false;
        
        foreach ($applicationKeywords as $keyword) {
            if (str_contains($emailLower, $keyword)) {
                $isApplicationEmail = true;
                break;
            }
        }

        if ($isApplicationEmail && $details['application_email'] === null) {
            $details['application_email'] = $email;
            $this->logger->debug(sprintf('TOOL-FOUND: Bewerbungs-E-Mail: %s', $email));
        } elseif ($details['general_email'] === null) {
            $details['general_email'] = $email;
            $this->logger->debug(sprintf('TOOL-FOUND: Allgemeine E-Mail: %s', $email));
        }
    }

    /**
     * Holt HTML-Content einer URL
     */
    private function fetchHtmlContent(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; CompanyFinderBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                ],
            ]);
            
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning(sprintf('HTTP-Fehler %d: %s', $response->getStatusCode(), $url));
                return null;
            }
            
            return $response->getContent();
            
        } catch (ExceptionInterface $e) {
            $this->logger->error(sprintf('Fehler beim Abrufen von %s: %s', $url, $e->getMessage()));
            return null;
        }
    }
    
    /**
     * Extrahiert E-Mail-Adressen aus Text
     */
    private function extractEmailsFromText(string $text): array
    {
        $emails = [];
        $pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        
        if (preg_match_all($pattern, $text, $matches)) {
            $emails = array_unique($matches[0]);
            
            $emails = array_filter($emails, function(string $email): bool {
                $emailLower = strtolower($email);
                
                // Erweiterte Blacklist
                $blacklist = [
                    'example.com', 'domain.com', 'test.com', 'sample.com',
                    'test@', 'email@', 'user@', 'name@', 'your@',
                    '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.pdf',
                    'placeholder', 'mustermann', 'musterfrau',
                    'sentry.io', 'wixpress.com', 'github.com', 'schema.org', // Tracking/Dev-Domains
                ];
                
                foreach ($blacklist as $blocked) {
                    if (str_contains($emailLower, $blocked)) {
                        return false;
                    }
                }
                
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });
        }
        
        return array_values($emails);
    }
    
    /**
     * Scraping mit spezifischen Selektoren
     */
    private function scrapeWithSelectors(string $url): array
    {
        $selectors = [
            'mailto_links' => 'a[href^="mailto"]::href',
            'person_elements' => '.name, .contact-name, .author, .staff-name, [itemprop="name"]::text',
            'contact_sections' => '.contact, .ansprechpartner, .team-member, .staff::text',
            'headings' => 'h1, h2, h3, h4::text',
        ];
        
        $scrapeResult = ($this->webScraper)($url, $selectors);
        $data = $scrapeResult['data'] ?? [];

        $contactPerson = null;
        $mailtoEmails = [];

        // Mailto-Links verarbeiten
        if (isset($data['mailto_links'])) {
            $mailtoLinks = is_array($data['mailto_links']) ? $data['mailto_links'] : [$data['mailto_links']];
            foreach ($mailtoLinks as $mailto) {
                if (is_string($mailto) && str_starts_with($mailto, 'mailto:')) {
                    $email = explode('?', str_replace('mailto:', '', $mailto))[0];
                    $mailtoEmails[] = trim($email);
                }
            }
        }
        
        // Ansprechpartner aus verschiedenen Quellen
        $personSources = ['person_elements', 'contact_sections', 'headings'];
        foreach ($personSources as $source) {
            if (isset($data[$source])) {
                $items = is_array($data[$source]) ? $data[$source] : [$data[$source]];
                foreach ($items as $item) {
                    $name = trim($item);
                    if ($this->isValidPersonName($name)) {
                        $contactPerson = $name;
                        break 2; // Beende beide Loops
                    }
                }
            }
        }
        
        return [
            'mailto_emails' => array_unique($mailtoEmails),
            'contact_person' => $contactPerson,
        ];
    }
    
    /**
     * Validiert Personennamen - DEUTLICH STRENGER
     */
    private function isValidPersonName(string $name): bool
    {
        $name = trim($name);

        // Längen-Check: 5-100 Zeichen
        if (strlen($name) < 5 || strlen($name) > 100) {
            return false;
        }

        // Muss 2-5 Wörter haben
        $wordCount = str_word_count($name);
        if ($wordCount < 2 || $wordCount > 5) {
            return false;
        }
        
        // Erweiterte Blacklist für Marketing-Phrasen und Nicht-Namen
        $blacklist = [
            // Standard-Phrases
            'impressum', 'kontakt', 'datenschutz', 'agb', 'copyright', 
            'karriere', 'jobs', 'bewerbung', 'stellenangebote',
            
            // Team/Gruppenbezeichnungen
            'unser team', 'ihre ansprechpartner', 'das team', 'unsere experten',
            'ihr kontakt', 'ansprechpartner', 'team', 'mitarbeiter',
            
            // Marketing-Slogans
            'finde', 'traumjob', 'mit uns', 'für dich', 'deine karriere',
            'jetzt bewerben', 'mehr erfahren', 'klicken sie hier',
            'entdecken sie', 'werden sie teil',
            
            // Positions-Titel ohne Namen
            'geschäftsführer', 'personalleiter', 'recruiter', 'hr manager',
            'head of', 'director', 'manager',
            
            // Andere
            'mehr informationen', 'weitere infos', 'details', 'hier klicken',
        ];
        
        $nameLower = strtolower($name);
        foreach ($blacklist as $blocked) {
            if (str_contains($nameLower, $blocked)) {
                return false;
            }
        }
        
        // Muss mindestens einen Großbuchstaben haben (typisch für Namen)
        if (!preg_match('/[A-ZÄÖÜ]/', $name)) {
            return false;
        }
        
        // Nicht NUR Großbuchstaben (typisch für Überschriften)
        if ($name === strtoupper($name) && strlen($name) > 10) {
            return false;
        }
        
        // Muss Buchstaben enthalten (nicht nur Zahlen/Sonderzeichen)
        if (!preg_match('/[a-zA-ZäöüÄÖÜß]/', $name)) {
            return false;
        }
        
        // Keine URLs oder E-Mails
        if (str_contains($nameLower, 'http') || str_contains($nameLower, '@') || str_contains($nameLower, 'www.')) {
            return false;
        }
        
        // Keine Imperative/Verben am Anfang (Marketing-Phrasen)
        $firstWord = strtolower(explode(' ', $name)[0]);
        $imperatives = ['finde', 'entdecke', 'werde', 'starte', 'bewirb', 'klicke', 'erfahre', 'besuche'];
        if (in_array($firstWord, $imperatives)) {
            return false;
        }
        
        return true;
    }
}