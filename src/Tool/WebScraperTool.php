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
    description: 'Accesses a given URL, parses its HTML content, and extracts data using CSS selectors. Useful for scraping publicly available web data.'
)]
final class WebScraperTool
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Scrapes a given URL and extracts data based on provided CSS selectors.
     *
     * @param string $url The URL to scrape. Must be a valid HTTP or HTTPS URL.
     * @param array $selectors An associative array where keys are data names (e.g., "title", "links")
     *                         and values are CSS selectors (e.g., "h1.product-title", "a.nav-link").
     *                         Each selector can optionally specify an attribute to extract (e.g., "a::href").
     *                         Supported attributes: href, src, alt, title, data-*, value, text (default).
     * @return array A structured array containing the extracted data, or an error message.
     */
    public function __invoke(
        #[With(pattern: '/^https?:\/\/[^\s$.?#].[^\s]*$/i')]
        string $url,
       
        array $selectors = []
    ): array {
        $this->logger->info(sprintf('WebScraperTool: Attempting to scrape URL: %s', $url), ['url' => $url, 'selectors' => $selectors]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                // Increase timeout for potentially slow loading pages
                'timeout' => 30,
                // Follow redirects
                'max_redirects' => 5,
                // Add a user agent to avoid being blocked by some sites
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; Symfony/AI-Agent; +https://your-agent-url.com)',
                ],
            ]);

            // Ensure a successful response (2xx)
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $errorMessage = sprintf('Failed to retrieve content from %s. Status Code: %d', $url, $statusCode);
                $this->logger->error($errorMessage, ['url' => $url, 'status_code' => $statusCode]);
                return ['error' => $errorMessage];
            }

            $htmlContent = $response->getContent();
            $crawler = new Crawler($htmlContent, $url);

            $extractedData = [];
            foreach ($selectors as $name => $selector) {
                // Split selector to check for attribute extraction (e.g., "a::href")
                $parts = explode('::', $selector, 2);
                $cssSelector = $parts[0];
                $attribute = $parts[1] ?? 'text'; // Default to text content if no attribute specified

                $node = $crawler->filter($cssSelector);
                if ($node->count() > 0) {
                    // Handle multiple results or single result
                    if ($node->count() > 1) {
                        $values = [];
                        foreach ($node as $item) {
                            $itemCrawler = new Crawler($item); // Create a new Crawler for each node
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
                    $this->logger->warning(sprintf('No element found for selector "%s" on URL: %s', $selector, $url));
                    $extractedData[$name] = null; // Indicate no data found
                }
            }

            $this->logger->info(sprintf('WebScraperTool: Successfully scraped URL: %s', $url), ['extracted_data' => $extractedData]);
            return ['status' => 'success', 'url' => $url, 'data' => $extractedData];

        } catch (\Exception $e) {
            $errorMessage = sprintf('WebScraperTool: An error occurred during scraping URL %s: %s', $url, $e->getMessage());
            $this->logger->error($errorMessage, ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'url' => $url]);
            return ['error' => $errorMessage];
        }
    }

    /**
     * Extracts the value from a Crawler node based on the specified attribute.
     *
     * @param Crawler $node The Crawler instance for the specific node.
     * @param string $attribute The attribute to extract (e.g., 'href', 'src', 'text').
     * @return string|null The extracted value or null if not found.
     */
    private function extractNodeValue(Crawler $node, string $attribute): ?string
    {
        if ($attribute === 'text') {
            return trim($node->text());
        }

        if ($node->nodeName() === 'input' && $attribute === 'value') {
            return $node->attr('value');
        }

        // Handle common attributes
        return $node->attr($attribute);
    }
}
