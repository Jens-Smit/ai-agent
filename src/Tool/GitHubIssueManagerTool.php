<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

#[AsTool(
    name: 'github_issue_manager',
    description: 'Manages GitHub issues, including fetching issues and updating their status.'
)]
final class GitHubIssueManagerTool
{
    private const GITHUB_API_BASE_URL = 'https://api.github.com/repos/';
    private string $githubRepo;
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly string $githubAccessToken,
        string $githubRepo // Format: owner/repo-name
    ) {
        // Fix: Entfernt 'https://github.com/' und führende/nachfolgende Slashes,
        // falls versehentlich die volle URL übergeben wurde.
        $this->githubRepo = trim(str_replace('https://github.com/', '', $githubRepo), '/');
    }

    /**
     * Retrieves issues from the configured GitHub repository.
     *
     * @param string $state The state of the issues to retrieve ('open', 'closed', or 'all').
     * @return array A structured array containing the status, data (list of issues), or error message.
     */
    #[AsTool(
        name: 'github_get_issues',
        description: 'Retrieves issues from the configured GitHub repository, optionally filtered by state.'
    )]
    public function getIssues(
        #[With(enum: ['open', 'closed', 'all'])]
        string $state = 'open'
    ): array {
        $this->logger->info('Attempting to fetch GitHub issues', ['repository' => $this->githubRepo, 'state' => $state]);

        try {
            $response = $this->httpClient->request('GET', self::GITHUB_API_BASE_URL . $this->githubRepo . '/issues', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->githubAccessToken,
                    'Accept' => 'application/vnd.github.v3+json',
                ],
                'query' => [
                    'state' => $state,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Successfully fetched GitHub issues', ['count' => count($content), 'repository' => $this->githubRepo]);
                return [
                    'status' => 'success',
                    'data' => $content,
                    'message' => 'Successfully fetched ' . count($content) . ' issues from ' . $this->githubRepo . ' with state "' . $state . '".',
                ];
            } else {
                $errorMessage = $content['message'] ?? 'Unknown error';
                $this->logger->warning('Failed to fetch GitHub issues', ['statusCode' => $statusCode, 'error' => $errorMessage]);
                return [
                    'status' => 'error',
                    'message' => 'Failed to fetch GitHub issues: ' . $errorMessage,
                    'statusCode' => $statusCode,
                ];
            }
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Client error fetching GitHub issues', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'client_error',
                'message' => 'GitHub API client error: ' . $e->getMessage(),
            ];
        } catch (ServerExceptionInterface $e) {
            $this->logger->error('Server error fetching GitHub issues', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'server_error',
                'message' => 'GitHub API server error: ' . $e->getMessage(),
            ];
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Network error fetching GitHub issues', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'network_error',
                'message' => 'Network or transport error when connecting to GitHub API: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            $this->logger->critical('Unexpected error fetching GitHub issues', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'critical_error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Updates the state of a specific GitHub issue.
     *
     * @param int $issueNumber The number of the issue to update.
     * @param string $state The new state of the issue ('open' or 'closed').
     * @return array A structured array containing the status, data (updated issue), or error message.
     */
    #[AsTool(
        name: 'github_update_issue',
        description: 'Updates the state (open or closed) of a specific GitHub issue by its number.'
    )]
    public function updateIssue(
        #[With(minimum: 1)]
        int $issueNumber,
        #[With(enum: ['open', 'closed'])]
        string $state
    ): array {
        $this->logger->info('Attempting to update GitHub issue state', ['issue_number' => $issueNumber, 'new_state' => $state, 'repository' => $this->githubRepo]);

        try {
            $response = $this->httpClient->request('PATCH', self::GITHUB_API_BASE_URL . $this->githubRepo . '/issues/' . $issueNumber, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->githubAccessToken,
                    'Accept' => 'application/vnd.github.v3+json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'state' => $state,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Successfully updated GitHub issue state', ['issue_number' => $issueNumber, 'new_state' => $state, 'repository' => $this->githubRepo]);
                return [
                    'status' => 'success',
                    'data' => $content,
                    'message' => 'Successfully updated issue #' . $issueNumber . ' to state "' . $state . '".',
                ];
            } else {
                $errorMessage = $content['message'] ?? 'Unknown error';
                $this->logger->warning('Failed to update GitHub issue state', ['statusCode' => $statusCode, 'error' => $errorMessage]);
                return [
                    'status' => 'error',
                    'message' => 'Failed to update GitHub issue: ' . $errorMessage,
                    'statusCode' => $statusCode,
                ];
            }
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Client error updating GitHub issue', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'client_error',
                'message' => 'GitHub API client error: ' . $e->getMessage(),
            ];
        } catch (ServerExceptionInterface $e) {
            $this->logger->error('Server error updating GitHub issue', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'server_error',
                'message' => 'GitHub API server error: ' . $e->getMessage(),
            ];
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Network error updating GitHub issue', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'network_error',
                'message' => 'Network or transport error when connecting to GitHub API: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            $this->logger->critical('Unexpected error updating GitHub issue', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'critical_error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Allows the tool to be invoked directly.
     *
     * @param string $state The state of the issues to retrieve ('open', 'closed', or 'all').
     * @return array The result of the `getIssues` method.
     */
    public function __invoke(string $state = 'open'): array
    {
        return $this->getIssues($state);
    }
}
