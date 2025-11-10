<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\ApiClientTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\RedirectionException;

class ApiClientToolTest extends TestCase
{
    private ApiClientTool $apiClientTool;
    private HttpClientInterface $mockHttpClient;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->apiClientTool = new ApiClientTool($this->mockHttpClient, $this->mockLogger);
    }

    public function testGetRequestSuccess(): void
    {
        $url = 'https://api.example.com/data';
        $responseData = ['key' => 'value'];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn(json_encode($responseData));

        $this->mockHttpClient->method('request')
            ->with('GET', $url, $this->anything())
            ->willReturn($mockResponse);

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(200, $result['statusCode']);
        $this->assertEquals($responseData, $result['data']);
    }

    public function testPostRequestSuccess(): void
    {
        $url = 'https://api.example.com/submit';
        $body = ['name' => 'Test', 'data' => 123];
        $responseData = ['status' => 'created'];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(201);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn(json_encode($responseData));

        $this->mockHttpClient->method('request')
            ->with('POST', $url, $this->callback(function ($options) use ($body) {
                return isset($options['json']) && $options['json'] === $body;
            }))
            ->willReturn($mockResponse);

        $result = $this->apiClientTool->__invoke('POST', $url, [], $body);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(201, $result['statusCode']);
        $this->assertEquals($responseData, $result['data']);
    }

    public function testGetRequestWithQueryParameters(): void
    {
        $url = 'https://api.example.com/search';
        $queryParams = ['q' => 'test', 'limit' => 5];
        $responseData = ['results' => ['item1', 'item2']];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn(json_encode($responseData));

        $this->mockHttpClient->method('request')
            ->with('GET', $url, $this->callback(function ($options) use ($queryParams) {
                return isset($options['query']) && $options['query'] === $queryParams;
            }))
            ->willReturn($mockResponse);

        $result = $this->apiClientTool->__invoke('GET', $url, [], [], $queryParams);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals($responseData, $result['data']);
    }

    public function testApiErrorResponse404(): void
    {
        $url = 'https://api.example.com/non-existent';
        $errorResponse = ['error' => 'Not Found'];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(404);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn(json_encode($errorResponse));

        $this->mockHttpClient->method('request')->willReturn($mockResponse);
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('API request to https://api.example.com/non-existent failed with status code: 404'));

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('error', $result['status']);
        $this->assertEquals(404, $result['statusCode']);
        $this->assertStringContainsString('failed with status code: 404', $result['message']);
        $this->assertEquals($errorResponse, $result['data']);
    }

    public function testClientException(): void
    {
        $url = 'https://api.example.com/bad-request';
        $exceptionMessage = '400 Bad Request';
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(400);
        $mockResponse->method('getContent')->willReturn('Bad Request Body');
        $clientException = new ClientException($exceptionMessage, $mockResponse);

        $this->mockHttpClient->method('request')->willThrowException($clientException);
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains($exceptionMessage));

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('error', $result['status']);
        $this->assertEquals(400, $result['statusCode']);
        $this->assertStringContainsString($exceptionMessage, $result['message']);
    }

    public function testServerException(): void
    {
        $url = 'https://api.example.com/internal-error';
        $exceptionMessage = '500 Internal Server Error';
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(500);
        $mockResponse->method('getContent')->willReturn('Internal Error Body');
        $serverException = new ServerException($exceptionMessage, $mockResponse);

        $this->mockHttpClient->method('request')->willThrowException($serverException);
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains($exceptionMessage));

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('error', $result['status']);
        $this->assertEquals(500, $result['statusCode']);
        $this->assertStringContainsString($exceptionMessage, $result['message']);
    }

    public function testRedirectionException(): void
    {
        $url = 'https://api.example.com/redirect';
        $exceptionMessage = '302 Found';
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(302);
        $mockResponse->method('getContent')->willReturn('');
        $redirectionException = new RedirectionException($exceptionMessage, $mockResponse);

        $this->mockHttpClient->method('request')->willThrowException($redirectionException);
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains($exceptionMessage));

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('error', $result['status']);
        $this->assertEquals(302, $result['statusCode']);
        $this->assertStringContainsString($exceptionMessage, $result['message']);
    }

    public function testGenericExceptionHandling(): void
    {
        $url = 'https://api.example.com/broken';
        $exceptionMessage = 'Network error';

        $this->mockHttpClient->method('request')->willThrowException(new \Exception($exceptionMessage));
        $this->mockLogger->expects($this->once())
            ->method('critical')
            ->with($this->stringContains($exceptionMessage));

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('An unexpected error occurred', $result['message']);
        $this->assertStringContainsString($exceptionMessage, $result['message']);
    }

    public function testNonJsonApiResponse(): void
    {
        $url = 'https://api.example.com/plaintext';
        $plainTextResponse = 'This is plain text content.';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['text/plain']]);
        $mockResponse->method('getContent')->willReturn($plainTextResponse);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('is not valid JSON.'));

        $result = $this->apiClientTool->__invoke('GET', $url);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(200, $result['statusCode']);
        $this->assertEquals($plainTextResponse, $result['data']);
    }

    public function testCustomHeadersAndTimeout(): void
    {
        $url = 'https://api.example.com/secured';
        $headers = ['Authorization' => 'Bearer token', 'X-Custom-Header' => 'value'];
        $timeout = 60;
        $responseData = ['status' => 'ok'];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn(json_encode($responseData));

        $this->mockHttpClient->method('request')
            ->with('GET', $url, $this->callback(function ($options) use ($headers, $timeout) {
                return isset($options['headers']) && $options['headers'] === $headers &&
                       isset($options['timeout']) && $options['timeout'] === $timeout;
            }))
            ->willReturn($mockResponse);

        $result = $this->apiClientTool->__invoke('GET', $url, $headers, [], [], $timeout);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals($responseData, $result['data']);
    }
}