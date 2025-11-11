<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\DevAgentTools;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Psr\Log\LoggerInterface;

class DevAgentToolsTest extends TestCase
{
    private DevAgentTools $devAgentTools;
    private MockHttpClient $mockHttpClient;
    private \PHPUnit\Framework\MockObject\MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockHttpClient = new MockHttpClient();
        $this->devAgentTools = new DevAgentTools($this->mockHttpClient, $this->logger);
    }

    public function testAssessAndRequestTool_NoNewToolNeeded(): void
    {
        $userPrompt = 'Analyze the sales data.';
        $availableTools = ['data_analyzer', 'report_generator'];
        $requiredToolDescription = '';

        $result = $this->devAgentTools->assessAndRequestTool($userPrompt, $availableTools, $requiredToolDescription);

        $this->assertEquals('info', $result['status']);
        $this->assertStringContainsString('No new tool requested', $result['message']);
    }

    public function testAssessAndRequestTool_NewToolRequestedSuccessfully(): void
    {
        $userPrompt = 'Generate a comprehensive market research report including competitor analysis.';
        $availableTools = ['report_generator', 'data_fetcher'];
        $requiredToolDescription = 'A tool that performs competitor analysis from public data sources.';

        $expectedDevAgentPromptPart = 'Develop a Symfony AI agent tool. The user's original task was: "Generate a comprehensive market research report including competitor analysis.". The agent currently has the following tools: report_generator, data_fetcher. A new tool is required with the following capabilities: "A tool that performs competitor analysis from public data sources.". Please name the tool 'generate_tool_performs' (adjust if necessary) and make it production-ready with comprehensive tests.';

        $this->mockHttpClient->setResponseFactory([
            new MockResponse(json_encode(['status' => 'received', 'message' => 'Tool generation initiated']), ['http_code' => 200]),
        ]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Successfully requested new tool from devAgent.');

        $result = $this->devAgentTools->assessAndRequestTool($userPrompt, $availableTools, $requiredToolDescription);

        $this->assertEquals('success', $result['status']);
        $this->assertStringContainsString('New tool request sent to devAgent.', $result['message']);
        $this->assertEquals(['status' => 'received', 'message' => 'Tool generation initiated'], $result['devAgentResponse']);
        $this->assertEquals($requiredToolDescription, $result['requestedToolDescription']);

        // You can inspect the request made by the HttpClient if needed, e.g., using a custom request handler.
        // For now, we rely on the MockHttpClient to have received the request.
    }

    public function testAssessAndRequestTool_NewToolRequestFailed(): void
    {
        $userPrompt = 'Optimize the database schema.';
        $availableTools = ['schema_analyzer'];
        $requiredToolDescription = 'A tool to generate and apply database migrations.';

        $this->mockHttpClient->setResponseFactory([
            new MockResponse(json_encode(['error' => 'Internal Server Error']), ['http_code' => 500]),
        ]);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to request new tool from devAgent.');

        $result = $this->devAgentTools->assessAndRequestTool($userPrompt, $availableTools, $requiredToolDescription);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Failed to send tool request to devAgent.', $result['message']);
        $this->assertEquals(500, $result['statusCode']);
        $this->assertEquals(['error' => 'Internal Server Error'], $result['devAgentResponse']);
    }

    public function testAssessAndRequestTool_HttpRequestException(): void
    {
        $userPrompt = 'Troubleshoot a network issue.';
        $availableTools = ['network_pinger'];
        $requiredToolDescription = 'A tool to analyze network traffic and identify bottlenecks.';

        // Simulate an exception during the HTTP request
        $this->mockHttpClient->setResponseFactory(function() {
            throw new \RuntimeException('Network connection refused.');
        });

        $this->logger->expects($this->once())
            ->method('critical')
            ->with('Exception while requesting new tool from devAgent.');

        $result = $this->devAgentTools->assessAndRequestTool($userPrompt, $availableTools, $requiredToolDescription);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('An exception occurred while sending tool request: Network connection refused.', $result['message']);
    }

    public function testSuggestToolName(): void
    {
        $reflectionClass = new \ReflectionClass(DevAgentTools::class);
        $method = $reflectionClass->getMethod('suggestToolName');
        $method->setAccessible(true); // Allow access to private method

        $this->assertEquals('generate_tool_manage_users', $method->invoke($this->devAgentTools, 'A tool to manage users and their permissions.'));
        $this->assertEquals('generate_tool_fetch_data', $method->invoke($this->devAgentTools, 'Fetch data from external API'));
        $this->assertEquals('generate_custom_tool', $method->invoke($this->devAgentTools, 'short')); // Fallback
        $this->assertEquals('generate_tool_analyze_sales', $method->invoke($this->devAgentTools, 'Tool to analyze sales reports and create summaries'));
    }
}
