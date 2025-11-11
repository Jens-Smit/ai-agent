<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\ToolCapabilityChecker;
use PHPUnit\Framework\TestCase;

final class ToolCapabilityCheckerTest extends TestCase
{
    private ToolCapabilityChecker $toolCapabilityChecker;

    protected function setUp(): void
    {
        $this->toolCapabilityChecker = new ToolCapabilityChecker();
    }

    public function testAgentIsCapableWithExistingTools(): void
    {
        $userPrompt = 'Please analyze the existing code base.';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('capable', $decodedResult['status']);
        self::assertArrayHasKey('message', $decodedResult);
        self::assertStringContainsString('capable', $decodedResult['message']);
        self::assertArrayHasKey('original_user_prompt', $decodedResult);
        self::assertEquals($userPrompt, $decodedResult['original_user_prompt']);
        self::assertArrayNotHasKey('dev_agent_prompt', $decodedResult);
        self::assertArrayNotHasKey('dev_agent_api_endpoint', $decodedResult);
    }

    public function testAgentSuggestsNewToolForDatabaseMigration(): void
    {
        $userPrompt = 'I need to create a database migration for a new user table.';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('new_tool_suggested', $decodedResult['status']);
        self::assertArrayHasKey('dev_agent_prompt', $decodedResult);
        self::assertStringContainsString('database_migrator', $decodedResult['dev_agent_prompt']);
        self::assertArrayHasKey('dev_agent_api_endpoint', $decodedResult);
        self::assertEquals('/api/devAgent', $decodedResult['dev_agent_api_endpoint']);
        self::assertArrayHasKey('original_user_prompt', $decodedResult);
        self::assertEquals($userPrompt, $decodedResult['original_user_prompt']);
        self::assertArrayHasKey('message', $decodedResult);
        self::assertStringContainsString('new tool suggested', $decodedResult['message']);
    }

    public function testAgentSuggestsNewToolForExternalApiDataFetching(): void
    {
        $userPrompt = 'The user wants to fetch data from an external API endpoint.';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('new_tool_suggested', $decodedResult['status']);
        self::assertArrayHasKey('dev_agent_prompt', $decodedResult);
        self::assertStringContainsString('api_data_fetcher', $decodedResult['dev_agent_prompt']);
        self::assertArrayHasKey('dev_agent_api_endpoint', $decodedResult);
        self::assertEquals('/api/devAgent', $decodedResult['dev_agent_api_endpoint']);
    }

    public function testAgentSuggestsNewToolForWebsiteScraping(): void
    {
        $userPrompt = 'I need a tool to scrape data from a website.';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('new_tool_suggested', $decodedResult['status']);
        self::assertArrayHasKey('dev_agent_prompt', $decodedResult);
        self::assertStringContainsString('web_content_scraper', $decodedResult['dev_agent_prompt']);
        self::assertArrayHasKey('dev_agent_api_endpoint', $decodedResult);
        self::assertEquals('/api/devAgent', $decodedResult['dev_agent_api_endpoint']);
    }

    public function testAgentSuggestsNewToolForSymfonyEntityGeneration(): void
    {
        $userPrompt = 'Could you generate a Symfony entity for a Product?';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('new_tool_suggested', $decodedResult['status']);
        self::assertArrayHasKey('dev_agent_prompt', $decodedResult);
        self::assertStringContainsString('symfony_entity_generator', $decodedResult['dev_agent_prompt']);
        self::assertArrayHasKey('dev_agent_api_endpoint', $decodedResult);
        self::assertEquals('/api/devAgent', $decodedResult['dev_agent_api_endpoint']);
    }

    public function testAgentSuggestsNewToolForGenericExternalSystemInteraction(): void
    {
        $userPrompt = 'I need to interact with a specific external system to update records.';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('new_tool_suggested', $decodedResult['status']);
        self::assertArrayHasKey('dev_agent_prompt', $decodedResult);
        self::assertStringContainsString('external_system_integrator', $decodedResult['dev_agent_prompt']);
        self::assertArrayHasKey('dev_agent_api_endpoint', $decodedResult);
        self::assertEquals('/api/devAgent', $decodedResult['dev_agent_api_endpoint']);
    }

    public function testAgentSuggestsNewToolForCustomAuthenticationFlow(): void
    {
        $userPrompt = 'Implement a custom authentication flow for social login.';
        $result = $this->toolCapabilityChecker->check($userPrompt);
        $decodedResult = json_decode($result, true);

        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('status', $decodedResult);
        self::assertEquals('new_tool_suggested', $decodedResult['status']);
        self::assertArrayHasKey('dev_agent_prompt', $decodedResult);
        self::assertStringContainsString('custom_auth_handler', $decodedResult['dev_agent_prompt']);
        self::assertArrayHasKey('dev_agent_api_endpoint', $decodedResult);
        self::assertEquals('/api/devAgent', $decodedResult['dev_agent_api_endpoint']);
    }
}
