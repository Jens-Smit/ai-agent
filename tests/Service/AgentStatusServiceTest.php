<?php

namespace App\Tests\Service;

use App\Service\AgentStatusService;
use PHPUnit\Framework\TestCase;

class AgentStatusServiceTest extends TestCase
{
    public function testAddAndGetStatuses(): void
    {
        $service = new AgentStatusService();

        $service->addStatus('Prompt received');
        $service->addStatus('Agent started processing');

        $statuses = $service->getStatuses();

        $this->assertCount(2, $statuses);
        $this->assertArrayHasKey('timestamp', $statuses[0]);
        $this->assertArrayHasKey('message', $statuses[0]);
        $this->assertEquals('Prompt received', $statuses[0]['message']);
        $this->assertEquals('Agent started processing', $statuses[1]['message']);
    }

    public function testClearStatuses(): void
    {
        $service = new AgentStatusService();

        $service->addStatus('Some initial status');
        $this->assertCount(1, $service->getStatuses());

        $service->clearStatuses();
        $this->assertEmpty($service->getStatuses());
    }

    public function testTimestampFormat(): void
    {
        $service = new AgentStatusService();
        $service->addStatus('Test message');
        $statuses = $service->getStatuses();

        $this->assertMatchesRegularExpression(
            '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/',
            $statuses[0]['timestamp']
        );
    }

    public function testNoStatusesInitially(): void
    {
        $service = new AgentStatusService();
        $this->assertEmpty($service->getStatuses());
    }
}
