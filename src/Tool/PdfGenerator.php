<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

#[AsTool(
    name: 'PdfGenerator',
    description: 'Generates a PDF file from a given text and saves it with the specified filename.'
)]
final class PdfGenerator
{
    private LoggerInterface $logger;
    private string $projectRootDir;

    public function __construct(LoggerInterface $logger, string $projectRootDir)
    {
        $this->logger = $logger;
        $this->projectRootDir = $projectRootDir;
    }

    /**
     * Generates a PDF file from the provided text content.
     *
     * @param string $text The text content to be included in the PDF.
     * @param string $filename The desired name for the PDF file (e.g., "document.pdf").
     * @return string The full path to the newly created PDF file upon success, or an error message if the PDF generation fails.
     */
    public function __invoke(
       
        string $text,
        #[With(pattern: '/^[^\/\\\?\%\*\:\|"<>]+\.pdf$/i')]
        string $filename
    ): string {
        $this->logger->info('PdfGenerator invoked.', [
            'text_preview' => substr($text, 0, 100), // nur Vorschau, nicht alles loggen
            'filename' => $filename,
            'projectRootDir' => $this->projectRootDir,
        ]);

        // Ensure the uploads directory exists
        $uploadDir = $this->projectRootDir . '/public/uploads';
        $this->logger->debug('Checking upload directory.', ['uploadDir' => $uploadDir]);

        if (!is_dir($uploadDir)) {
            $this->logger->warning('Upload directory does not exist, attempting to create.', ['uploadDir' => $uploadDir]);
            if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                $errorMessage = sprintf('Could not create upload directory "%s"', $uploadDir);
                $this->logger->error($errorMessage);
                return 'ERROR: ' . $errorMessage;
            }
            $this->logger->info('Upload directory created successfully.', ['uploadDir' => $uploadDir]);
        }

        $filePath = $uploadDir . '/' . $filename;
        $this->logger->debug('Target file path resolved.', ['filePath' => $filePath]);

        try {
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);

            $this->logger->debug('Dompdf options configured.', [
                'isHtml5ParserEnabled' => $options->get('isHtml5ParserEnabled'),
                'isRemoteEnabled' => $options->get('isRemoteEnabled'),
            ]);

            $dompdf = new Dompdf($options);

            // Basic HTML wrapper for the text
            $html = '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>Generated PDF</title>
                    <style>
                        body { font-family: sans-serif; }
                        pre { white-space: pre-wrap; word-wrap: break-word; }
                    </style>
                </head>
                <body>
                    <h1>Generated Document</h1>
                    <pre>' . htmlspecialchars($text) . '</pre>
                </body>
                </html>
            ';

            $this->logger->debug('HTML prepared for PDF rendering.', [
                'html_preview' => substr($html, 0, 200) . '...'
            ]);

            $dompdf->loadHtml($html);
            $this->logger->info('HTML loaded into Dompdf.');

            $dompdf->setPaper('A4', 'portrait');
            $this->logger->info('Paper size set.', ['size' => 'A4', 'orientation' => 'portrait']);

            $dompdf->render();
            $this->logger->info('PDF rendering completed.');

            file_put_contents($filePath, $dompdf->output());
            $this->logger->info('PDF file written successfully.', ['path' => $filePath]);

            return $filePath;
        } catch (Exception $e) {
            $errorMessage = 'PDF generation failed: ' . $e->getMessage();
            $this->logger->error($errorMessage, [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return 'ERROR: ' . $errorMessage;
        }
    }
}
