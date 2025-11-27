<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;

#[AsTool(
    name: 'web_scraper',
    description: 'Accesses a given URL, parses its HTML content, crawls relevant internal links (like career, jobs, contact pages), and extracts data using CSS selectors. Can also read robots.txt for better navigation.'
)]
final class WebScraperTool
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private array $visitedUrls = [];
    private int $maxDepth = 2; // Maximale Crawl-Tiefe
    private int $maxUrlsPerDomain = 10; // Maximale URLs pro Domain

    // Priorisierte Keywords für relevante Seiten
    private array $relevantKeywords = [
        'karriere', 'career', 'jobs', 'stellenangebote', 'bewerbung', 'application',
        'kontakt', 'contact', 'impressum', 'imprint', 'about', 'ueber-uns',
        'team', 'personal', 'hr', 'human-resources', 'join', 'arbeiten-bei'
    ];

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Scrapes a given URL and extracts data based on provided CSS selectors.
     * Automatically crawls relevant internal links for better coverage.
     *
     * @param string $url The URL to scrape. Must be a valid HTTP or HTTPS URL.
     * @param array $selectors An associative array where keys are data names (e.g., "title", "links")
     *                         and values are CSS selectors (e.g., "h1.product-title", "a.nav-link").
     *                         Each selector can optionally specify an attribute to extract (e.g., "a::href").
     *                         Supported attributes: href, src, alt, title, data-*, value, text (default).
     * @param bool $crawlInternalLinks Whether to crawl relevant internal links (default: true).
     * @param int $maxDepth Maximum crawling depth (default: 2).
     * @return array A structured array containing the extracted data from all crawled pages.
     */
    public function __invoke(
        #[With(pattern: '/^https?:\/\/[^\s$.?#].[^\s]*$/i')]
        string $url,
        array $selectors = [],
        bool $crawlInternalLinks = true,
        int $maxDepth = 2
    ): array {
        $this->logger->info(sprintf('WebScraperTool: Starting intelligent crawl for URL: %s', $url));
        
        // Reset für jeden neuen Scraping-Vorgang
        $this->visitedUrls = [];
        $this->maxDepth = $maxDepth;
        
        $baseDomain = $this->extractBaseDomain($url);
        
        // Prüfe robots.txt für bessere Navigation
        $robotsInfo = $this->parseRobotsTxt($baseDomain);
        $this->logger->debug('Robots.txt info:', $robotsInfo);
        
        // Sammle alle relevanten URLs
        $urlsToCrawl = $this->discoverRelevantUrls($url, $baseDomain, $robotsInfo);
        
        $this->logger->info(sprintf('Found %d relevant URLs to crawl', count($urlsToCrawl)));
        
        // Crawle alle URLs und sammle Daten
        $aggregatedData = [];
        $allExtractedData = [];
        
        foreach ($urlsToCrawl as $crawlUrl) {
            if (count($this->visitedUrls) >= $this->maxUrlsPerDomain) {
                $this->logger->info('Max URLs per domain reached, stopping crawl');
                break;
            }
            
            $pageData = $this->scrapeSinglePage($crawlUrl, $selectors);
            
            if ($pageData['status'] === 'success') {
                $allExtractedData[$crawlUrl] = $pageData['data'];
                
                // Merge die Daten intelligent
                foreach ($pageData['data'] as $key => $value) {
                    if (!isset($aggregatedData[$key])) {
                        $aggregatedData[$key] = [];
                    }
                    
                    if (is_array($value)) {
                        $aggregatedData[$key] = array_merge($aggregatedData[$key], $value);
                    } elseif ($value !== null) {
                        $aggregatedData[$key][] = $value;
                    }
                }
            }
        }
        
        // Dedupliziere aggregierte Daten
        foreach ($aggregatedData as $key => $value) {
            if (is_array($value)) {
                $aggregatedData[$key] = array_values(array_unique($value));
            }
        }
        
        $this->logger->info(sprintf('WebScraperTool: Completed intelligent crawl. Visited %d URLs', count($this->visitedUrls)));
        
        return [
            'status' => 'success',
            'base_url' => $url,
            'urls_crawled' => array_values($this->visitedUrls),
            'data' => $aggregatedData,
            'detailed_data_by_url' => $allExtractedData
        ];
    }

    /**
     * Extrahiert die Basis-Domain aus einer URL
     */
    private function extractBaseDomain(string $url): string
    {
        $parsed = parse_url($url);
        return sprintf('%s://%s', $parsed['scheme'] ?? 'https', $parsed['host'] ?? '');
    }

    /**
     * Liest und parst die robots.txt einer Domain
     */
    private function parseRobotsTxt(string $baseDomain): array
    {
        $robotsUrl = rtrim($baseDomain, '/') . '/robots.txt';
        $info = [
            'sitemaps' => [],
            'disallowed' => [],
            'allowed' => []
        ];
        
        try {
            $response = $this->httpClient->request('GET', $robotsUrl, [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; Symfony/AI-Agent)',
                ],
            ]);
            
            if ($response->getStatusCode() === 200) {
                $content = $response->getContent();
                $lines = explode("\n", $content);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    
                    if (stripos($line, 'Sitemap:') === 0) {
                        $sitemap = trim(substr($line, 8));
                        $info['sitemaps'][] = $sitemap;
                    } elseif (stripos($line, 'Disallow:') === 0) {
                        $path = trim(substr($line, 9));
                        if ($path) {
                            $info['disallowed'][] = $path;
                        }
                    } elseif (stripos($line, 'Allow:') === 0) {
                        $path = trim(substr($line, 6));
                        if ($path) {
                            $info['allowed'][] = $path;
                        }
                    }
                }
                
                $this->logger->info('Successfully parsed robots.txt', ['sitemaps' => count($info['sitemaps'])]);
            }
        } catch (\Exception $e) {
            $this->logger->debug(sprintf('Could not fetch robots.txt: %s', $e->getMessage()));
        }
        
        return $info;
    }

    /**
     * Entdeckt relevante URLs auf der Website
     */
    private function discoverRelevantUrls(string $startUrl, string $baseDomain, array $robotsInfo): array
    {
        $relevantUrls = [$startUrl];
        $urlsToCheck = [$startUrl];
        $checkedUrls = [];
        
        while (!empty($urlsToCheck) && count($relevantUrls) < $this->maxUrlsPerDomain) {
            $currentUrl = array_shift($urlsToCheck);
            
            if (in_array($currentUrl, $checkedUrls)) {
                continue;
            }
            
            $checkedUrls[] = $currentUrl;
            
            try {
                $links = $this->extractInternalLinks($currentUrl, $baseDomain, $robotsInfo);
                
                foreach ($links as $link) {
                    if (count($relevantUrls) >= $this->maxUrlsPerDomain) {
                        break 2;
                    }
                    
                    if (!in_array($link, $relevantUrls) && !in_array($link, $checkedUrls)) {
                        if ($this->isRelevantUrl($link)) {
                            $relevantUrls[] = $link;
                            $this->logger->debug(sprintf('Found relevant URL: %s', $link));
                        }
                        
                        // Füge zur Check-Liste hinzu, wenn noch nicht zu tief
                        if (count($checkedUrls) < $this->maxDepth * 5) {
                            $urlsToCheck[] = $link;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug(sprintf('Error discovering links on %s: %s', $currentUrl, $e->getMessage()));
            }
        }
        
        return $relevantUrls;
    }

    /**
     * Extrahiert interne Links von einer Seite
     */
    private function extractInternalLinks(string $url, string $baseDomain, array $robotsInfo): array
    {
        $links = [];
        
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 20,
                'max_redirects' => 3,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
            ]);
            
            if ($response->getStatusCode() >= 400) {
                return $links;
            }
            
            $htmlContent = $response->getContent();
            $crawler = new Crawler($htmlContent, $url);
            
            // Extrahiere alle Links
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseDomain, $url, $robotsInfo) {
                $href = $node->attr('href');
                
                if (!$href) {
                    return;
                }
                
                // Konvertiere relative URLs zu absoluten
                $absoluteUrl = $this->makeAbsoluteUrl($href, $url);
                
                // Prüfe ob es ein interner Link ist
                if ($this->isInternalLink($absoluteUrl, $baseDomain) && 
                    !$this->isDisallowedByRobots($absoluteUrl, $baseDomain, $robotsInfo)) {
                    $links[] = $this->normalizeUrl($absoluteUrl);
                }
            });
            
        } catch (\Exception $e) {
            $this->logger->debug(sprintf('Error extracting links from %s: %s', $url, $e->getMessage()));
        }
        
        return array_unique($links);
    }

    /**
     * Prüft, ob eine URL relevant ist (enthält relevante Keywords)
     */
    private function isRelevantUrl(string $url): bool
    {
        $urlLower = strtolower($url);
        
        foreach ($this->relevantKeywords as $keyword) {
            if (str_contains($urlLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Macht eine relative URL absolut
     */
    private function makeAbsoluteUrl(string $href, string $baseUrl): string
    {
        // Bereits absolute URL
        if (preg_match('/^https?:\/\//i', $href)) {
            return $href;
        }
        
        $baseParts = parse_url($baseUrl);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        
        // Protocol-relative URL
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        
        // Absoluter Pfad
        if (str_starts_with($href, '/')) {
            return sprintf('%s://%s%s', $scheme, $host, $href);
        }
        
        // Relativer Pfad
        $path = $baseParts['path'] ?? '/';
        $pathDir = dirname($path);
        return sprintf('%s://%s%s/%s', $scheme, $host, $pathDir, $href);
    }

    /**
     * Prüft ob ein Link intern ist
     */
    private function isInternalLink(string $url, string $baseDomain): bool
    {
        return str_starts_with($url, $baseDomain);
    }

    /**
     * Prüft ob URL von robots.txt blockiert wird
     */
    private function isDisallowedByRobots(string $url, string $baseDomain, array $robotsInfo): bool
    {
        if (empty($robotsInfo['disallowed'])) {
            return false;
        }
        
        $path = str_replace($baseDomain, '', $url);
        
        foreach ($robotsInfo['disallowed'] as $disallowedPath) {
            if (str_starts_with($path, $disallowedPath)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Normalisiert eine URL (entfernt Query-Parameter und Fragmente)
     */
    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        
        return sprintf(
            '%s://%s%s',
            $parsed['scheme'] ?? 'https',
            $parsed['host'] ?? '',
            $parsed['path'] ?? '/'
        );
    }

    /**
     * Scraped eine einzelne Seite
     */
    private function scrapeSinglePage(string $url, array $selectors): array
    {
        if (in_array($url, $this->visitedUrls)) {
            return ['status' => 'skipped', 'reason' => 'already_visited'];
        }
        
        $this->visitedUrls[] = $url;
        $this->logger->debug(sprintf('Scraping page: %s', $url));
        
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

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $errorMessage = sprintf('Failed to retrieve content from %s. Status Code: %d', $url, $statusCode);
                $this->logger->warning($errorMessage);
                return ['status' => 'error', 'error' => $errorMessage];
            }

            $htmlContent = $response->getContent();
            $crawler = new Crawler($htmlContent, $url);

            $extractedData = [];
            foreach ($selectors as $name => $selector) {
                $parts = explode('::', $selector, 2);
                $cssSelector = $parts[0];
                $attribute = $parts[1] ?? 'text';

                $node = $crawler->filter($cssSelector);
                if ($node->count() > 0) {
                    if ($node->count() > 1) {
                        $values = [];
                        foreach ($node as $item) {
                            $itemCrawler = new Crawler($item);
                            $value = $this->extractNodeValue($itemCrawler, $attribute);
                            if ($value !== null) {
                                $values[] = $value;
                            }
                        }
                        $extractedData[$name] = $values;
                    } else {
                        $value = $this->extractNodeValue($node, $attribute);
                        if ($value !== null) {
                            $extractedData[$name] = $value;
                        }
                    }
                } else {
                    $extractedData[$name] = null;
                }
            }

            return ['status' => 'success', 'url' => $url, 'data' => $extractedData];

        } catch (\Exception $e) {
            $errorMessage = sprintf('Error scraping %s: %s', $url, $e->getMessage());
            $this->logger->error($errorMessage);
            return ['status' => 'error', 'error' => $errorMessage];
        }
    }

    /**
     * Extracts the value from a Crawler node based on the specified attribute.
     */
    private function extractNodeValue(Crawler $node, string $attribute): ?string
    {
        if ($attribute === 'text') {
            return trim($node->text());
        }

        if ($node->nodeName() === 'input' && $attribute === 'value') {
            return $node->attr('value');
        }

        return $node->attr($attribute);
    }
}