<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\BatchPdfGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

final class BatchPdfGeneratorTest extends TestCase
{
    private LoggerInterface $logger;
    private vfsStreamDirectory $root;
    private string $vfsRootPath;
    private string $generatedPdfsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->root = vfsStream::setup('root');
        
        // Set a consistent base output path for testing within vfsStream
        $this->vfsRootPath = vfsStream::url('root');
        $this->generatedPdfsDir = $this->vfsRootPath . DIRECTORY_SEPARATOR . 'generated_pdfs';
        
        // Ensure the base directory for generated PDFs exists in vfsStream
        if (!is_dir($this->generatedPdfsDir)) {
            mkdir($this->generatedPdfsDir, 0777, true);
        }
    }

    public function testGeneratePdfsSuccessfully(): void
    {
        $tool = new BatchPdfGenerator($this->logger, $this->vfsRootPath); // Pass vfsStream path
        $pdfs = [
            ['text' => 'Hello World 1', 'filename' => 'doc1.pdf'],
            ['text' => 'Hello World 2', 'filename' => 'doc2.pdf'],
        ];

        $this->logger->expects($this->exactly(4))
            ->method('info'); // Start, doc1, doc2, Finish
        $this->logger->expects($this->never())
            ->method('warning');
        $this->logger->expects($this->never())
            ->method('error');

        $result = $tool($pdfs);

        $this->assertEquals('success', $result['status']);
        $this->assertStringContainsString('All PDFs generated successfully.', $result['message']);
        $this->assertCount(2, $result['successful_filenames']);
        $this->assertStringContainsString('doc1.pdf', $result['successful_filenames'][0]);
        $this->assertStringContainsString('doc2.pdf', $result['successful_filenames'][1]);
        $this->assertEmpty($result['errors']);

        // Verify files exist in the virtual file system
        $this->assertTrue($this->root->getChild('generated_pdfs/doc1.pdf')->hasContent("PDF Content for: Hello World 1"));
        $this->assertTrue($this->root->getChild('generated_pdfs/doc2.pdf')->hasContent("PDF Content for: Hello World 2"));
    }

    public function testGeneratePdfsWithInvalidInput(): void
    {
        $tool = new BatchPdfGenerator($this->logger, $this->vfsRootPath); // Pass vfsStream path
        $pdfs = [
            ['text' => 'Valid content', 'filename' => 'valid.pdf'],
            ['text' => 'Missing filename'], // Invalid: missing filename
            ['filename' => 'missing_text.pdf'], // Invalid: missing text
            ['text' => '', 'filename' => 'empty_text.pdf'], // Invalid: empty text
            ['text' => 'Valid content 2', 'filename' => 'valid2.pdf'],
        ];

        $this->logger->expects($this->exactly(3))
            ->method('info'); // Start, valid.pdf, valid2.pdf, Finish
        $this->logger->expects($this->exactly(3))
            ->method('warning'); // Missing filename, Missing text, Empty text
        $this->logger->expects($this->never())
            ->method('error');

        $result = $tool($pdfs);

        $this->assertEquals('partial_success', $result['status']);
        $this->assertStringContainsString('Some PDFs generated successfully, but others failed.', $result['message']);
        $this->assertCount(2, $result['successful_filenames']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('valid.pdf', $result['successful_filenames'][0]);
        $this->assertStringContainsString('valid2.pdf', $result['successful_filenames'][1]);

        $this->assertStringContainsString('"text" or "filename" is missing or invalid.', $result['errors'][0]['message']);
        $this->assertStringContainsString('"text" or "filename" is missing or invalid.', $result['errors'][1]['message']);
        $this->assertStringContainsString('"text" or "filename" is missing or invalid.', $result['errors'][2]['message']);
        
        // Verify only valid files exist
        $this->assertTrue($this->root->getChild('generated_pdfs/valid.pdf')->hasContent("PDF Content for: Valid content"));
        $this->assertTrue($this->root->getChild('generated_pdfs/valid2.pdf')->hasContent("PDF Content for: Valid content 2"));
        $this->assertFalse($this->root->hasChild('generated_pdfs/missing_text.pdf'));
    }

    public function testGeneratePdfsWithNoPdfsProvided(): void
    {
        $tool = new BatchPdfGenerator($this->logger, $this->vfsRootPath);
        $pdfs = [];

        $this->logger->expects($this->once())
            ->method('info'); // Start
        $this->logger->expects($this->once())
            ->method('warning'); // No PDFs provided
        $this->logger->expects($this->never())
            ->method('error');

        $result = $tool($pdfs);

        $this->assertEquals('warning', $result['status']);
        $this->assertStringContainsString('No PDFs were provided for generation.', $result['message']);
        $this->assertEmpty($result['successful_filenames']);
        $this->assertEmpty($result['errors']);
    }

    public function testGeneratePdfsWithErrorDuringGeneration(): void
    {
        $this->root->getChild('generated_pdfs')->chmod(0444); // Make directory read-only
        
        $tool = new BatchPdfGenerator($this->logger, $this->vfsRootPath);

        $this->logger->expects($this->once())
            ->method('info'); // Start
        $this->logger->expects($this->once())
            ->method('error'); // Error for bad.pdf
        $this->logger->expects($this->never())
            ->method('warning');

        $pdfs = [
            ['text' => 'Error content', 'filename' => 'bad.pdf'], 
        ];
        
        $result = $tool($pdfs);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Failed to generate any PDFs.', $result['message']);
        $this->assertEmpty($result['successful_filenames']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Failed to generate PDF "bad.pdf":', $result['errors'][0]['message']);
        
        $this->assertFalse($this->root->hasChild('generated_pdfs/bad.pdf'));
    }
}
