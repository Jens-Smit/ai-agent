<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Tool\PdfGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class PdfGeneratorTest extends TestCase
{
    private LoggerInterface $logger;
    private string $testProjectRootDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->testProjectRootDir = sys_get_temp_dir() . '/pdf_generator_test_root';
        $this->filesystem = new Filesystem();

        if ($this->filesystem->exists($this->testProjectRootDir)) {
            $this->filesystem->remove($this->testProjectRootDir);
        }
        $this->filesystem->mkdir($this->testProjectRootDir);
        $this->filesystem->mkdir($this->testProjectRootDir . '/public/uploads'); // Ensure upload dir exists for successful tests
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->testProjectRootDir)) {
            $this->filesystem->remove($this->testProjectRootDir);
        }
    }

    public function testGeneratePdfSuccessfully(): void
    {
        $pdfGenerator = new PdfGenerator($this->logger, $this->testProjectRootDir);
        $text = 'This is a test PDF content.';
        $filename = 'test_document.pdf';
        $expectedPath = $this->testProjectRootDir . '/public/uploads/' . $filename;

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('PdfGenerator tool started.'));

        $result = $pdfGenerator($text, $filename);

        $this->assertStringEndsWith($filename, $result);
        $this->assertStringContainsString('/public/uploads/', $result);
        $this->assertTrue($this->filesystem->exists($result));
        $this->assertStringStartsWith($this->testProjectRootDir, $result);

        // Clean up the generated file
        $this->filesystem->remove($result);
    }

    public function testGeneratePdfWithUnwritableDirectory(): void
    {
        $unwritableUploadDir = $this->testProjectRootDir . '/public/uploads';
        // Make the upload directory unwritable
        $this->filesystem->chmod($unwritableUploadDir, 0444);

        $pdfGenerator = new PdfGenerator($this->logger, $this->testProjectRootDir);
        $text = 'Some text.';
        $filename = 'unwritable_test.pdf';

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('PdfGenerator tool started.'));
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('PDF generation failed:'));

        $result = $pdfGenerator($text, $filename);

        $this->assertStringStartsWith('ERROR:', $result);
        $this->assertStringContainsString('PDF generation failed:', $result);

        // Restore permissions for tearDown
        $this->filesystem->chmod($unwritableUploadDir, 0777);
    }

    public function testGeneratePdfWithDirectoryCreationFailure(): void
    {
        // Simulate a scenario where the initial directory creation fails
        $failedProjectRootDir = sys_get_temp_dir() . '/pdf_generator_fail_root';
        $this->filesystem->remove($failedProjectRootDir);

        // Prevent creation of the public/uploads directory by making the root itself unwritable (if possible)
        // This is tricky to reliably simulate for mkdir failure directly within a test in some environments
        // without more advanced mocking or a custom wrapper around filesystem operations.

        // However, the tool explicitly checks for is_dir() and mkdir() success.
        // Let's ensure that the root dir itself is clean and then the tool attempts to create subdirs.

        $pdfGenerator = new PdfGenerator($this->logger, $failedProjectRootDir);
        $text = 'Some text.';
        $filename = 'dir_fail_test.pdf';

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('PdfGenerator tool started.'));
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Could not create upload directory'));

        $result = $pdfGenerator($text, $filename);

        $this->assertStringStartsWith('ERROR:', $result);
        $this->assertStringContainsString('Could not create upload directory', $result);

        $this->filesystem->remove($failedProjectRootDir);
    }
}
