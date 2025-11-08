<?php

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'save_code_file',
    description: 'Saves the provided code content to a file with a given name in the generated_code directory. Returns SUCCESS if the file was saved, ERROR otherwise.'
)]
final class CodeSaverTool
{
    private const BASE_DIR = __DIR__.'/../../generated_code/';
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $this->logger->info('CodeSaverTool initialized. Base Directory: ' . self::BASE_DIR);

        if (!is_dir(self::BASE_DIR)) {
            $this->logger->warning('Base directory does not exist. Attempting to create: ' . self::BASE_DIR);
            
            if (!mkdir(self::BASE_DIR, 0777, true)) {
                $this->logger->error('Failed to create base directory: ' . self::BASE_DIR);
            } else {
                $this->logger->info('Successfully created base directory: ' . self::BASE_DIR);
            }
        }
    }

    /**
     * Saves code content to a file.
     * 
     * @param string $filename The name of the file (e.g., "index.html"). Must have a valid extension.
     * @param string $content  The code content to save.
     * @return string A message indicating success or failure.
     */
    public function __invoke(
        #[With(pattern: '/^[^\\/]+\\.(html|js|php|yaml|json|css|md|txt|xml)$/i')]
        string $filename,
        string $content
    ): string {
        // basename() prevents path manipulation (e.g., ../)
        $safeFilename = basename($filename);
        $filepath = self::BASE_DIR . $safeFilename;
        
        $this->logger->info('Attempting to write file', [
            'filename' => $safeFilename,
            'filepath' => $filepath,
            'content_length' => strlen($content)
        ]);

        // Check if file already exists
        if (file_exists($filepath)) {
            $this->logger->warning('File already exists, will overwrite: ' . $filepath);
        }

        // Write file
        $bytesWritten = @file_put_contents($filepath, $content);
        
        if ($bytesWritten !== false) {
            $this->logger->info('Successfully wrote file', [
                'filepath' => $filepath,
                'bytes_written' => $bytesWritten
            ]);
            
            // Verify file was created
            if (file_exists($filepath)) {
                return "SUCCESS: Code was saved to file: " . $safeFilename;
            }
            
            $this->logger->error('File write reported success but file does not exist: ' . $filepath);
            return "ERROR: File write succeeded but verification failed for: " . $safeFilename;
        }
        
        $lastError = error_get_last();
        $this->logger->error('File write failed', [
            'filepath' => $filepath,
            'error' => $lastError ? $lastError['message'] : 'Unknown error'
        ]);
        
        return "ERROR: Failed to save code to file: " . $safeFilename . 
               " (Check directory permissions for: " . self::BASE_DIR . ")";
    }
}