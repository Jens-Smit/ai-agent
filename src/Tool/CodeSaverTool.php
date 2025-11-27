<?php

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem; // Add this import

#[AsTool(
    name: 'save_code_file',
    description: 'Saves the provided code content to a file with a given name in the generated_code directory, supporting subdirectories within generated_code. Returns SUCCESS if the file was saved, ERROR otherwise.'
)]
final class CodeSaverTool
{
    private const BASE_DIR = __DIR__.'/../../generated_code/';
    private LoggerInterface $logger;
    private Filesystem $filesystem; // Add Filesystem

    public function __construct(LoggerInterface $logger, Filesystem $filesystem) // Inject Filesystem
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem; // Initialize Filesystem

        $this->logger->info('CodeSaverTool initialized. Base Directory: ' . self::BASE_DIR);

        if (!$this->filesystem->exists(self::BASE_DIR)) { // Use filesystem->exists
            $this->logger->warning('Base directory does not exist. Attempting to create: ' . self::BASE_DIR);
            
            if (!$this->filesystem->mkdir(self::BASE_DIR, 0777, true)) { // Use filesystem->mkdir
                $this->logger->error('Failed to create base directory: ' . self::BASE_DIR);
            } else {
                $this->logger->info('Successfully created base directory: ' . self::BASE_DIR);
            }
        }
    }

    /**
     * Saves code content to a file.
     * 
     * @param string $filename The name of the file, optionally including a subdirectory within generated_code/ (e.g., "my_run_id/index.html"). Must have a valid extension.
     * @param string $content  The code content to save.
     * @return string A message indicating success or failure.
     */
    public function __invoke(
        #[With(pattern: '/^([a-zA-Z0-9_-]+\/)?[^\\/]+\\.(html|js|php|yaml|json|css|md|txt|xml|env)$/i')] // Allow optional directory prefix and .env files
        string $filename,
        string $content
    ): string {
        $filepath = self::BASE_DIR . $filename; // Use $filename directly

        // Ensure the directory for the file exists
        $directory = dirname($filepath);
        if (!$this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory, 0777, true);
            $this->logger->info('Created directory for generated file', ['directory' => $directory]);
        }
        
        $this->logger->info('Attempting to write file', [
            'filename' => $filename, // Use $filename directly for logging
            'filepath' => $filepath,
            'content_length' => strlen($content)
        ]);

        if ($this->filesystem->exists($filepath)) { // Use filesystem->exists
            $this->logger->warning('File already exists, will overwrite: ' . $filepath);
        }

        // Write file
        $bytesWritten = @file_put_contents($filepath, $content);
        
        if ($bytesWritten !== false) {
            $this->logger->info('Successfully wrote file', [
                'filepath' => $filepath,
                'bytes_written' => $bytesWritten
            ]);
            
            if ($this->filesystem->exists($filepath)) { // Use filesystem->exists
                return "SUCCESS: Code was saved to file: " . $filename;
            }
            
            $this->logger->error('File write reported success but file does not exist: ' . $filepath);
            return "ERROR: File write succeeded but verification failed for: " . $filename;
        }
        
        $lastError = error_get_last();
        $this->logger->error('File write failed', [
            'filepath' => $filepath,
            'error' => $lastError ? $lastError['message'] : 'Unknown error'
        ]);
        
        return "ERROR: Failed to save code to file: " . $filename . 
               " (Check directory permissions for: " . $directory . ") "; // Log the actual directory
    }
}