<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\GitHubIssueManagerTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitHubIssueManagerToolTest extends TestCase
{
    private GitHubIssueManagerTool $tool;
    private HttpClientInterface $httpClientMock;
    private string $githubAccessToken = 'ghp_test_token';
    private string $githubRepo = 'test_owner/test_repo';

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->tool = new GitHubIssueManagerTool(
            new NullLogger(),
            $this->httpClientMock,
            $this->githubAccessToken,
            $this->githubRepo
        );
    }

    public function testGetIssuesSuccessfulExecution(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([['number' => 1, 'title' => 'Test Issue', 'state' => 'open']]);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = $this->tool->getIssues('open');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertSame('Test Issue', $result['data'][0]['title']);
    }

    public function testGetIssuesEmptyResult(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([]);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = $this->tool->getIssues('open');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testGetIssuesApiError(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('toArray')->willReturn(['message' => 'Not Found']);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = $this->tool->getIssues('open');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Not Found', $result['message']);
        $this->assertSame(404, $result['statusCode']);
    }

    public function testUpdateIssueSuccessfulExecution(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn(['number' => 1, 'state' => 'closed']);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = $this->tool->updateIssue(1, 'closed');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('closed', $result['data']['state']);
    }

    public function testUpdateIssueApiError(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('toArray')->willReturn(['message' => 'Validation Failed']);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = $this->tool->updateIssue(999, 'closed');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Validation Failed', $result['message']);
        $this->assertSame(422, $result['statusCode']);
    }
}
