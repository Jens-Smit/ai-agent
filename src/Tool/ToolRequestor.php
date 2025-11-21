<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * A service that allows the AI agent to assess its capabilities and request new tools from the devAgent by creating a GitHub Issue.
 */
#[AsTool(
    name: 'request_tool_development',
    description: 'Requests a new Symfony AI Agent tool by creating a GitHub Issue in the designated repository. This tool should only be called once the required tool description (prompt for DevAgent) has been fully formulated. Input is the complete, detailed prompt required by the DevAgent, which will become the issue body.'
)]
final class ToolRequestor
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $githubToken;
    private string $githubRepo;

    /**
     * @param string $githubToken The GITHUB_ACCESS_TOKEN from .env, used for API authorization.
     * @param string $githubRepo The target repository in "owner/repo" format (e.g., "myuser/myproject") or a full URL to the repository.
     */
    public function __construct(
        LoggerInterface $logger,
        string $githubToken,
        string $githubRepo,
        HttpClientInterface $httpClient
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->githubToken = $githubToken;
        
        $originalRepoValue = $githubRepo;

        // Versuchen, den owner/repo-Teil zu extrahieren, falls eine vollständige URL übergeben wurde.
        if (str_starts_with($githubRepo, 'http')) {
            $parsedUrl = parse_url($githubRepo);
            // Der Pfad sollte '/owner/repo' sein
            $path = trim($parsedUrl['path'] ?? '', '/');
            
            // Wenn der Pfad den erwarteten 'owner/repo'-Teil enthält (Genau ein Slash), verwenden wir diesen.
            if (substr_count($path, '/') === 1) {
                $this->githubRepo = $path;
            } else {
                // Ansonsten verwenden wir den Originalwert für die Fehlerprüfung.
                $this->githubRepo = $githubRepo;
            }
        } else {
            $this->githubRepo = $githubRepo;
        }

        // Sicherstellen, dass das Repository-Format korrekt ist (muss jetzt nur noch den owner/repo-Teil enthalten)
        if (substr_count($this->githubRepo, '/') !== 1) {
             throw new \InvalidArgumentException(
                 'The githubRepo parameter must be in "owner/repo" format (e.g., "jens-Smit/ai-agent"). Found: ' . $originalRepoValue
             );
        }
    }

    /**
     * Erstellt ein neues Issue im konfigurierten GitHub-Repository.
     *
     * Die DevAgent-Anforderung wird als Body des Issues verwendet. Der Titel wird aus der Anforderung generiert.
     *
     * @param string $devAgentPrompt Der vollständige, detaillierte Prompt, der die Anforderungen für das neue Tool enthält (PHP-Klasse, Methoden, Tests, etc.).
     * @return array Eine Statusmeldung des Anforderungsprozesses, einschließlich der URL zum erstellten Issue.
     */
    public function __invoke(string $devAgentPrompt): array
    {
        $issueTitle = $this->generateIssueTitle($devAgentPrompt);
        $apiUrl = sprintf('https://api.github.com/repos/%s/issues', $this->githubRepo);

        $this->logger->info('Attempting to create GitHub Issue for tool development.', [
            'repo' => $this->githubRepo,
            'title' => $issueTitle
        ]);

        try {
            $response = $this->httpClient->request('POST', $apiUrl, [
                'json' => [
                    'title' => $issueTitle,
                    'body' => "## DevAgent Tool Request\n\n" . $devAgentPrompt
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->githubToken,
                    'Accept' => 'application/vnd.github.v3+json',
                    'Content-Type' => 'application/json',
                    // GitHub requires a user-agent
                    'User-Agent' => 'Symfony-AI-Agent-ToolRequestor',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode === 201) { // 201 Created is the success status for issue creation
                $issueUrl = $content['html_url'] ?? 'N/A';
                $this->logger->info('Successfully created new GitHub Issue.', ['statusCode' => $statusCode, 'issueUrl' => $issueUrl]);
                return [
                    'status' => 'success',
                    'message' => 'New tool request successfully filed as a GitHub Issue.',
                    'issueTitle' => $issueTitle,
                    'issueUrl' => $issueUrl,
                ];
            } else {
                $errorMessage = $content['message'] ?? 'Unknown error';
                $this->logger->error('Failed to create GitHub Issue.', ['statusCode' => $statusCode, 'error' => $errorMessage]);
                return [
                    'status' => 'error',
                    'message' => 'GitHub API responded with an error (HTTP status code was not 201).',
                    'statusCode' => $statusCode,
                    'githubError' => $errorMessage,
                ];
            }
        } catch (\Exception $e) {
            $this->logger->critical('Exception while requesting new tool via GitHub.', ['error' => $e->getMessage()]);
            return [
                'status' => 'critical_error',
                'message' => 'A critical error occurred while communicating with GitHub: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Generates a descriptive title for the GitHub Issue based on the prompt.
     */
    private function generateIssueTitle(string $prompt): string
    {
        // Simple extraction logic: find a class name or key action
        if (preg_match('/class\s+([A-Za-z0-9_]+)/i', $prompt, $matches)) {
            $baseName = $matches[1];
        } elseif (preg_match('/Create a tool for\s+(.*?)[\.\n]/i', $prompt, $matches)) {
            $baseName = $matches[1];
        } elseif (strlen($prompt) > 50) {
            // Use the first part of the prompt
            $baseName = trim(substr($prompt, 0, 50)) . '...';
        } else {
            $baseName = trim($prompt);
        }

        // Clean up and format as a title
        $baseName = preg_replace('/\s+/', ' ', $baseName); // Remove excess whitespace
        return 'Tool Development Request: ' . ucfirst(trim($baseName));
    }
}