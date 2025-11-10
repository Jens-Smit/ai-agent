<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\WebScraperTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WebScraperToolTest extends TestCase
{
    private WebScraperTool $webScraperTool;
    private HttpClientInterface $mockHttpClient;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->webScraperTool = new WebScraperTool($this->mockHttpClient, $this->mockLogger);
    }

    public function testScrapeSuccessWithTextSelector(): void
    {
        $url = 'https://example.com';
        $selectors = ['title' => 'h1', 'paragraph' => 'p.intro'];
        $html = '<html><body><h1>Test Title</h1><p class="intro">Welcome!</p></body></html>';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn($html);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals($url, $result['url']);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('Test Title', $result['data']['title']);
        $this->assertEquals('Welcome!', $result['data']['paragraph']);
    }

    public function testScrapeSuccessWithAttributeSelector(): void
    {
        $url = 'https://example.com';
        $selectors = ['link' => 'a::href', 'image_alt' => 'img::alt'];
        $html = '<html><body><a href="/some-link">Link</a><img src="image.jpg" alt="Test Image"></body></html>';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn($html);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('/some-link', $result['data']['link']);
        $this->assertEquals('Test Image', $result['data']['image_alt']);
    }

    public function testScrapeSuccessWithMultipleResults(): void
    {
        $url = 'https://example.com';
        $selectors = ['items' => 'li::text'];
        $html = '<html><body><ul><li>Item 1</li><li>Item 2</li></ul></body></html>';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn($html);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('items', $result['data']);
        $this->assertEquals(['Item 1', 'Item 2'], $result['data']['items']);
    }

    public function testScrapeNoElementsFound(): void
    {
        $url = 'https://example.com';
        $selectors = ['non_existent' => '.non-existent-class'];
        $html = '<html><body><p>Some content</p></body></html>';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn($html);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('No element found for selector ".non-existent-class"'));

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertNull($result['data']['non_existent']);
    }

    public function testScrapeHttpError(): void
    {
        $url = 'https://example.com';
        $selectors = ['title' => 'h1'];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(404);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to retrieve content from https://example.com. Status Code: 404'));

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('Failed to retrieve content from https://example.com. Status Code: 404', $result['error']);
    }

    public function testScrapeExceptionHandling(): void
    {
        $url = 'https://invalid-url';
        $selectors = ['title' => 'h1'];
        $exceptionMessage = 'Could not resolve host: invalid-url';

        $this->mockHttpClient->method('request')->willThrowException(new \Exception($exceptionMessage));
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains($exceptionMessage));

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertStringContainsString('An error occurred during scraping URL https://invalid-url: ' . $exceptionMessage, $result['error']);
    }

    public function testScrapeEmptyHtml(): void
    {
        $url = 'https://example.com';
        $selectors = ['title' => 'h1'];
        $html = '';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn($html);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertNull($result['data']['title']);
    }

    public function testScrapeWithComplexSelectors(): void
    {
        $url = 'https://example.com';
        $selectors = [
            'product_name' => 'div.product-detail h2',
            'price' => 'span.price::text',
            'description' => '#product-description p',
            'main_image' => 'div.gallery img.main-thumb::src',
            'data_id' => 'div.product::data-id'
        ];
        $html = '<html><body>
            <div class="product" data-id="12345">
                <div class="product-detail">
                    <h2>Awesome Product</h2>
                    <span class="price">$99.99</span>
                </div>
                <div id="product-description">
                    <p>This is a great product.</p>
                    <p>It has many features.</p>
                </div>
                <div class="gallery">
                    <img class="main-thumb" src="/images/awesome-product.jpg" alt="Awesome Product Image">
                </div>
            </div>
            </body></html>';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn($html);

        $this->mockHttpClient->method('request')->willReturn($mockResponse);

        $result = $this->webScraperTool->__invoke($url, $selectors);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Awesome Product', $result['data']['product_name']);
        $this->assertEquals('$99.99', $result['data']['price']);
        // When multiple <p> elements are found under #product-description, only the text of the first one is returned by default
        // For all texts, the tool currently returns an array of values as per the `extractNodeValue` logic.
        $this->assertEquals(['This is a great product.', 'It has many features.'], $result['data']['description']);
        $this->assertEquals('/images/awesome-product.jpg', $result['data']['main_image']);
        $this->assertEquals('12345', $result['data']['data_id']);
    }
}