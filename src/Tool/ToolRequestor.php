<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * A service that allows the AI agent to assess its capabilities and request new tools from the devAgent.
 */
#[AsTool(
    name: 'request_tool_development',
    description: 'Requests the DevAgent API to create a new Symfony AI Agent tool. This tool should only be called once the required tool description (prompt for DevAgent) has been fully formulated. Input is the complete, detailed prompt required by the DevAgent.'
)]
final class ToolRequestor
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(
    LoggerInterface $logger,
        HttpClientInterface|null $httpClient
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([]);
        $this->logger = $logger;
    }



    /**
     * Sendet den finalen Entwicklungs-Prompt an den /api/devAgent Endpunkt.
     * * @param string $devAgentPrompt Der vollständige, detaillierte Prompt, der die Anforderungen für das neue Tool enthält (PHP-Klasse, Methoden, Tests, etc.).
     * @return array Eine Statusmeldung des Anforderungsprozesses.
     */
    public function __invoke(string $devAgentPrompt): array 
    {
        $this->logger->info('Attempting to send tool development request to devAgent.', ['prompt_length' => strlen($devAgentPrompt)]);
        
        try {
            // HINWEIS: Aktualisieren Sie die URL, falls Ihr DevAgent nicht auf localhost:8000 läuft.
            $response = $this->httpClient->request('POST', 'http://127.0.0.1:8000/api/devAgent', [
                'json' => ['prompt' => $devAgentPrompt],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Successfully requested new tool from devAgent.', ['statusCode' => $statusCode]);
                return [
                    'status' => 'success',
                    'message' => 'New tool request successfully sent to DevAgent. Development is now starting in the background.',
                    'statusCode' => $statusCode,
                    'devAgentResponse' => $content,
                ];
            } else {
                $this->logger->error('Failed to request new tool from devAgent.', ['statusCode' => $statusCode, 'response' => $content]);
                return [
                    'status' => 'error',
                    'message' => 'DevAgent responded with an error (HTTP status code was not 2xx).',
                    'statusCode' => $statusCode,
                    'devAgentResponse' => $content,
                ];
            }
        } catch (\Exception $e) {
            $this->logger->critical('Exception while requesting new tool from devAgent.', ['error' => $e->getMessage()]);
            return [
                'status' => 'critical_error',
                'message' => 'A critical error occurred while communicating with the DevAgent: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Suggests a tool name based on the description.
     * This is a very basic implementation and can be improved.
     */
    private function suggestToolName(string $description): string
    {
        $description = strtolower($description);
        $description = preg_replace('/[^a-z0-9\s]/', '', $description);
        $words = explode(' ', $description);
        $words = array_filter($words, fn($word) => strlen($word) > 2); // Filter out short words
        $name = implode('_', array_slice($words, 0, 3)); // Take first 3 meaningful words
        return 'generate_' . ($name ?: 'custom_tool');
    }
}