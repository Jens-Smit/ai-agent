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
    name: 'assess_and_request_tool',
    description: 'Assesses if the AI agent can fulfill a user\'s request with its current tools. If not, it generates a new tool request for the devAgent API to create a Symfony AI agent tool.'
)]
final class DevAgentTools
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient = null, LoggerInterface $logger)
    {
        // Use default HttpClient if not provided, useful for testing or simpler environments
        $this->httpClient = $httpClient ?? HttpClient::create();
        $this->logger = $logger;
    }

    /**
     * Assesses if the AI agent can fulfill a user's request with its current tools.
     * If not, it generates a new tool request for the devAgent API to create a Symfony AI agent tool.
     *
     * @param string $userPrompt      The original prompt from the user describing their task.
     * @param array  $availableTools      A list of names of the tools currently available to the AI agent.
     * @param string $requiredToolDescription A clear description of the new tool needed by the agent.
     *                                        If this is empty, it implies no new tool is needed.
     * @return array A status message indicating whether a new tool was requested or if current tools are sufficient.
     */
    public function __invoke(
        string $userPrompt,
        array $availableTools,
        string $requiredToolDescription = ''
    ): array {
        if (!empty($requiredToolDescription)) {
            $toolNameSuggestion = $this->suggestToolName($requiredToolDescription);
            $devAgentPrompt = sprintf(
                "Develop a Symfony AI agent tool. The user's original task was: \"%s\". The agent currently has the following tools: %s. A new tool is required with the following capabilities: \"%s\". Please name the tool '%s' (adjust if necessary) and make it production-ready with comprehensive tests.",
                $userPrompt,
                implode(', ', $availableTools),
                $requiredToolDescription,
                $toolNameSuggestion
            );

            try {
                // The actual API call is encapsulated here. For sandbox, this will be mocked.
                $response = $this->httpClient->request('POST', 'http://localhost/api/devAgent', [
                    'json' => ['prompt' => $devAgentPrompt],
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $content = $response->toArray(false); // Do not throw for 4xx/5xx

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->info('Successfully requested new tool from devAgent.', ['statusCode' => $statusCode, 'response' => $content]);
                    return [
                        'status' => 'success',
                        'message' => 'New tool request sent to devAgent.',
                        'devAgentResponse' => $content,
                        'requestedToolDescription' => $requiredToolDescription,
                    ];
                } else {
                    $this->logger->error('Failed to request new tool from devAgent.', ['statusCode' => $statusCode, 'response' => $content]);
                    return [
                        'status' => 'error',
                        'message' => 'Failed to send tool request to devAgent.',
                        'statusCode' => $statusCode,
                        'devAgentResponse' => $content,
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->critical('Exception while requesting new tool from devAgent.', ['error' => $e->getMessage()]);
                return [
                    'status' => 'error',
                    'message' => 'An exception occurred while sending tool request: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'info',
            'message' => 'No new tool requested, as requiredToolDescription was empty. Agent deemed current tools sufficient or did not identify a specific tool need.',
        ];
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