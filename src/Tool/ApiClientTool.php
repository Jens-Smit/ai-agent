<?php

declare(strict_types=1);

namespace App\Tool;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\RedirectionException;

#[AsTool(
    name: 'api_client',
    description: 'Makes HTTP requests to external APIs. Supports GET, POST, PUT, DELETE methods with JSON payloads and headers. Handles API responses and errors.'
)]
final class ApiClientTool
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Makes an HTTP request to an external API.
     *
     * @param string $method The HTTP method (GET, POST, PUT, DELETE). Case-insensitive.
     * @param string $url The API endpoint URL. Must be a valid HTTP or HTTPS URL.
     * @param array $headers An associative array of request headers (e.g., ["Content-Type" => "application/json"]).
     * @param array $body A JSON-serializable array for the request body (for POST/PUT requests).
     * @param array $queryParameters An associative array of query parameters (e.g., ["page" => 1, "limit" => 10]).
     * @param int $timeout Request timeout in seconds.
     * @return array A structured array containing the API response (JSON decoded), status code, and headers, or an error message.
     */
    public function __invoke(
        #[With(enum: ['GET', 'POST', 'PUT', 'DELETE'])]
        string $method = 'GET',
        #[With(pattern: '/^https?:\/\/[^\s$.?#].[^\s]*$/i')]
        string $url,
        array $headers = [],
        array $body = [],
        array $queryParameters = [],
        int $timeout = 30
    ): array {
        $method = strtoupper($method);
        $this->logger->info(sprintf('ApiClientTool: Making %s request to URL: %s', $method, $url), [
            'method' => $method,
            'url' => $url,
            'headers' => array_keys($headers),
            'queryParameters' => $queryParameters,
            'body_size' => strlen(json_encode($body)),
            'timeout' => $timeout,
        ]);

        $options = [
            'timeout' => $timeout,
            'headers' => $headers,
            'query' => $queryParameters,
        ];

        if (in_array($method, ['POST', 'PUT']) && !empty($body)) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);

            $statusCode = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $content = $response->getContent(false); // Get raw content without throwing exceptions
            
            // Attempt to decode JSON content, gracefully handle non-JSON responses
            $decodedContent = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decodedContent = null; // Not a valid JSON response
                $this->logger->warning(sprintf('API response for %s is not valid JSON.', $url));
            }
            
            // Check for successful status codes (2xx)
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info(sprintf('ApiClientTool: Request to %s successful. Status Code: %d', $url, $statusCode), ['status_code' => $statusCode, 'response_length' => strlen($content)]);
                return [
                    'status' => 'success',
                    'statusCode' => $statusCode,
                    'headers' => $responseHeaders,
                    'data' => $decodedContent ?? $content // Return decoded JSON or raw content if not JSON
                ];
            } else {
                // For non-2xx but valid HTTP responses (e.g., 4xx, 5xx)
                $errorMessage = sprintf('API request to %s failed with status code: %d. Response: %s', $url, $statusCode, substr($content, 0, 200));
                $this->logger->error($errorMessage, ['url' => $url, 'status_code' => $statusCode, 'response' => $content]);
                return [
                    'status' => 'error',
                    'statusCode' => $statusCode,
                    'message' => $errorMessage,
                    'headers' => $responseHeaders,
                    'data' => $decodedContent ?? $content
                ];
            }
        } catch (ClientException $e) {
            $errorMessage = sprintf('API Client Error for %s: %s', $url, $e->getMessage());
            $statusCode = $e->getResponse()->getStatusCode();
            $content = $e->getResponse()->getContent(false);
            $this->logger->error($errorMessage, ['exception' => $e->getMessage(), 'url' => $url, 'status_code' => $statusCode, 'response' => $content]);
            return ['status' => 'error', 'message' => $errorMessage, 'statusCode' => $statusCode, 'data' => json_decode($content, true) ?? $content];
        } catch (ServerException $e) {
            $errorMessage = sprintf('API Server Error for %s: %s', $url, $e->getMessage());
            $statusCode = $e->getResponse()->getStatusCode();
            $content = $e->getResponse()->getContent(false);
            $this->logger->error($errorMessage, ['exception' => $e->getMessage(), 'url' => $url, 'status_code' => $statusCode, 'response' => $content]);
            return ['status' => 'error', 'message' => $errorMessage, 'statusCode' => $statusCode, 'data' => json_decode($content, true) ?? $content];
        } catch (RedirectionException $e) {
            $errorMessage = sprintf('API Redirection Error for %s: %s', $url, $e->getMessage());
            $statusCode = $e->getResponse()->getStatusCode();
            $content = $e->getResponse()->getContent(false);
            $this->logger->warning($errorMessage, ['exception' => $e->getMessage(), 'url' => $url, 'status_code' => $statusCode, 'response' => $content]);
            return ['status' => 'error', 'message' => $errorMessage, 'statusCode' => $statusCode, 'data' => json_decode($content, true) ?? $content];
        } catch (\Exception $e) {
            $errorMessage = sprintf('ApiClientTool: An unexpected error occurred during API request to %s: %s', $url, $e->getMessage());
            $this->logger->critical($errorMessage, ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'url' => $url]);
            return ['status' => 'error', 'message' => $errorMessage];
        }
    }
}
