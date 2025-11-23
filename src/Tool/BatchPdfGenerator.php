<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'batch_pdf_generator',
    description: 'Generates multiple PDF files from text content and saves them.'
)]
final class BatchPdfGenerator
{
    private readonly string $baseOutputPath;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $baseOutputPath = '' // Allow passing path, or use empty string for default logic
    ) {
        // Initialize readonly property in the constructor
        $this->baseOutputPath = empty($baseOutputPath) ? sys_get_temp_dir() : $baseOutputPath;
    }

    /**
     * Generates multiple PDF files based on provided text content and filenames.
     *
     * @param array<array{text: string, filename: string}> $pdfs A list of dictionaries, where each dictionary
     *     must contain 'text' (the content for the PDF) and 'filename' (the desired name for the PDF file).
     * @return array<string, mixed> A structured result indicating status, a list of successfully generated
     *     PDF filenames, and any encountered errors.
     */
    public function __invoke(
        #[With(
            items: [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                    'filename' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_\\-\\.]+\\.pdf$']
                ],
                'required' => ['text', 'filename']
            ],
            type: 'array'
        )]
        array $pdfs
    ): array {
        $this->logger->info('BatchPdfGenerator tool execution started.', ['number_of_pdfs' => count($pdfs)]);
        $successfulFilenames = [];
        $errors = [];

        if (empty($pdfs)) {
            $this->logger->warning('No PDFs provided for generation.');
            return [
                'status' => 'warning',
                'message' => 'No PDFs were provided for generation.',
                'successful_filenames' => [],
                'errors' => []
            ];
        }

        $targetDirectory = $this->baseOutputPath . DIRECTORY_SEPARATOR . 'generated_pdfs';

        foreach ($pdfs as $index => $pdfData) {
            $text = $pdfData['text'] ?? null;
            $filename = $pdfData['filename'] ?? null;

            if (!is_string($text) || empty($text) || !is_string($filename) || empty($filename)) {
                $errorMsg = sprintf('Invalid data for PDF #%d: "text" or "filename" is missing or invalid.', $index);
                $this->logger->warning($errorMsg, ['pdf_data' => $pdfData]);
                $errors[] = ['index' => $index, 'message' => $errorMsg, 'data' => $pdfData];
                continue;
            }

            try {
                // Ensure the target directory exists
                if (!is_dir($targetDirectory)) {
                    mkdir($targetDirectory, 0777, true);
                }
                $fullPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
                file_put_contents($fullPath, "PDF Content for: " . $text); // Simplified content

                $successfulFilenames[] = $fullPath;
                $this->logger->info(sprintf('Successfully generated PDF: %s', $filename));

            } catch (\Exception $e) {
                $errorMsg = sprintf('Failed to generate PDF "%s": %s', $filename, $e->getMessage());
                $this->logger->error($errorMsg, ['exception' => $e->getTraceAsString()]);
                $errors[] = ['index' => $index, 'filename' => $filename, 'message' => $errorMsg];
            }
        }

        $status = empty($errors) ? 'success' : (empty($successfulFilenames) ? 'error' : 'partial_success');
        $message = match ($status) {
            'success' => 'All PDFs generated successfully.',
            'partial_success' => 'Some PDFs generated successfully, but others failed.',
            'error' => 'Failed to generate any PDFs.',
            default => 'Unknown status.'
        };

        $this->logger->info('BatchPdfGenerator tool execution finished.', [
            'status' => $status,
            'successful_count' => count($successfulFilenames),
            'error_count' => count($errors)
        ]);

        return [
            'status' => $status,
            'message' => $message,
            'successful_filenames' => $successfulFilenames,
            'errors' => $errors,
        ];
    }
}
