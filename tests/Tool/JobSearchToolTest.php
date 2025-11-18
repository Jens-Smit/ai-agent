<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\JobSearchTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class JobSearchToolTest extends TestCase
{
    private JobSearchTool $tool;
    private HttpClientInterface $httpClientMock;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->logger = new NullLogger();
        $this->tool = new JobSearchTool($this->httpClientMock, $this->logger);
    }

    public function testSuccessfulJobSearch(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'stellenangebote' => [
                ['hashId' => 'test1', 'beruf' => 'Entwickler'],
                ['hashId' => 'test2', 'beruf' => 'Designer'],
            ],
            'maxErgebnisse' => '2',
            'page' => '1',
            'size' => '2',
        ]);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = ($this->tool)(
            what: 'Entwickler',
            where: 'Berlin',
            page: 1,
            size: 2
        );

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']['stellenangebote']);
        $this->assertSame('Entwickler', $result['data']['stellenangebote'][0]['beruf']);
    }

    public function testApiErrorHandling(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('toArray')->willReturn(['message' => 'Not Found']);

        $this->httpClientMock->method('request')->willReturn($responseMock);

        $result = ($this->tool)(
            what: 'NonExistentJob'
        );

        $this->assertSame('api_error', $result['status']);
        $this->assertSame(404, $result['statusCode']);
        $this->assertArrayHasKey('details', $result);
        $this->assertSame('Not Found', $result['details']['message']);
    }

    public function testNetworkErrorHandling(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException($this->createMock(TransportExceptionInterface::class));

        $result = ($this->tool)(
            what: 'AnyJob'
        );

        $this->assertSame('network_error', $result['status']);
        $this->assertStringContainsString('Failed to connect', $result['message']);
    }

    public function testHttpExceptionHandling(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->willReturn('{"error": "Internal Server Error"}');
        $responseMock->method('toArray')->willReturn(['error' => 'Internal Server Error']);

        $httpExceptionMock = $this->createMock(HttpExceptionInterface::class);
        $httpExceptionMock->method('getResponse')->willReturn($responseMock);
        $httpExceptionMock->method('getMessage')->willReturn('Server error');

        $this->httpClientMock->method('request')
            ->willThrowException($httpExceptionMock);

        $result = ($this->tool)(
            what: 'AnyJob'
        );

        $this->assertSame('http_error', $result['status']);
        $this->assertStringContainsString('An HTTP error occurred', $result['message']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertArrayHasKey('details', $result);
        $this->assertSame('Internal Server Error', $result['details']['error']);
    }

    public function testUnexpectedErrorHandling(): void
    {
        $this->httpClientMock->method('request')
            ->willThrowException(new \Exception('Something went wrong'));

        $result = ($this->tool)(
            what: 'AnyJob'
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('An unexpected error occurred', $result['message']);
    }

    public function testParameterMapping(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn(['stellenangebote' => []]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                JobSearchTool::API_BASE_URL . '/pc/v4/jobs',
                $this->callback(function($options) {
                    $this->assertArrayHasKey('query', $options);
                    $this->assertEquals('IT', $options['query']['berufsfeld']);
                    $this->assertEquals('true', $options['query']['zeitarbeit']);
                    $this->assertEquals('1', $options['query']['angebotsart']);
                    $this->assertEquals('1;2', $options['query']['befristung']);
                    $this->assertEquals('vz;tz', $options['query']['arbeitszeit']);
                    $this->assertEquals('true', $options['query']['behinderung']);
                    $this->assertEquals('true', $options['query']['corona']);
                    $this->assertEquals('50', $options['query']['umkreis']);
                    $this->assertEquals('30', $options['query']['veroeffentlichtseit']);
                    $this->assertEquals('Deutsche Bahn', $options['query']['arbeitgeber']);
                    return true;
                })
            );

        ($this->tool)(
            jobField: 'IT',
            temporaryWork: true,
            offerType: 1,
            fixedTerm: '1;2',
            workingHours: 'vz;tz',
            disability: true,
            corona: true,
            radius: 50,
            publishedSince: 30,
            employer: 'Deutsche Bahn'
        );
    }

    public function testDefaultParameterValues(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn(['stellenangebote' => []]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                JobSearchTool::API_BASE_URL . '/pc/v4/jobs',
                $this->callback(function($options) {
                    $this->assertArrayHasKey('query', $options);
                    $this->assertEquals(1, $options['query']['page']);
                    $this->assertEquals(50, $options['query']['size']);
                    $this->assertEquals('true', $options['query']['zeitarbeit']);
                    // Ensure optional parameters are not set if null
                    $this->assertArrayNotHasKey('was', $options['query']);
                    $this->assertArrayNotHasKey('wo', $options['query']);
                    return true;
                })
            );

        ($this->tool)();
    }
}
