<?php
// src/Tool/PdfGenerator.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Entity\UserDocument;
use App\Service\UserDocumentService;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

#[AsTool(
    name: 'PdfGenerator',
    description: 'Generates a PDF file from text, saves it in the user\'s document storage, and returns the document ID for later use as email attachment.'
)]
final class PdfGenerator
{
    public function __construct(
        private LoggerInterface $logger,
        private Security $security,
        private UserDocumentService $documentService
    ) {}

    /**
     * Generates a PDF file from the provided text content.
     *
     * @param string $text The text content to be included in the PDF.
     * @param string $filename The desired name for the PDF file (e.g., "Bewerbung.pdf").
     * @return array Returns structured data with document_id, filepath, and filename
     */
    public function __invoke(
        string $text,
        #[With(pattern: '/^[^\/\\\?\%\*\:\|"<>]+\.pdf$/i')]
        string $filename
    ): array {
        /** @var User|null $user */
        $user = $this->security->getUser();

        if (!$user) {
            $error = 'No authenticated user found - cannot generate PDF';
            $this->logger->error($error);
            return [
                'success' => false,
                'error' => $error,
                'document_id' => null,
                'filepath' => null,
                'filename' => $filename
            ];
        }

        $this->logger->info('PdfGenerator invoked', [
            'userId' => $user->getId(),
            'filename' => $filename,
            'text_preview' => substr($text, 0, 100)
        ]);

        try {
            // 1. Generiere PDF-Content mit Dompdf
            $pdfContent = $this->generatePdfContent($text);
            
            // 2. Erstelle temporäre Datei
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            file_put_contents($tempPath, $pdfContent);
            
            $this->logger->info('PDF generated successfully', [
                'tempFile' => $tempPath,
                'size' => strlen($pdfContent)
            ]);

            // 3. Erstelle UploadedFile-kompatibles File-Objekt
            $file = new \Symfony\Component\HttpFoundation\File\File($tempPath);
            
            // 4. Speichere über UserDocumentService (wie hochgeladene Dateien)
            $document = $this->documentService->uploadGeneratedFile(
                user: $user,
                file: $file,
                originalFilename: $filename,
                mimeType: 'application/pdf',
                category: UserDocument::CATEGORY_GENERATED,
                displayName: pathinfo($filename, PATHINFO_FILENAME),
                description: 'Automatisch generiertes Bewerbungsschreiben',
                tags: ['generated', 'cover_letter', 'pdf']
            );

            // 5. Cleanup: Schließe temporäre Datei
            fclose($tempFile);

            $result = [
                'success' => true,
                'document_id' => $document->getId(),
                'filepath' => $document->getFullPath(),
                'filename' => $document->getOriginalFilename(),
                'size' => $document->getFileSize(),
                'stored_filename' => $document->getStoredFilename(),
                'storage_path' => $document->getStoragePath(),
                'download_url' => sprintf('/api/documents/%d/download', $document->getId())
            ];

            $this->logger->info('PDF saved to database and filesystem', $result);

            return $result;

        } catch (\Exception $e) {
            $error = 'PDF generation failed: ' . $e->getMessage();
            $this->logger->error($error, [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $error,
                'document_id' => null,
                'filepath' => null,
                'filename' => $filename
            ];
        }
    }

    /**
     * Generiert PDF-Content mit Dompdf
     */
    private function generatePdfContent(string $text): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);

        // Professionelles HTML-Template für Bewerbungsschreiben
        $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Bewerbungsschreiben</title>
                <style>
                    @page {
                        margin: 2.5cm 2cm;
                    }
                    body {
                        font-family: "DejaVu Sans", Arial, sans-serif;
                        font-size: 11pt;
                        line-height: 1.6;
                        color: #333;
                    }
                    .content {
                        white-space: pre-wrap;
                        word-wrap: break-word;
                    }
                    h1 {
                        font-size: 14pt;
                        margin-bottom: 1.5em;
                    }
                </style>
            </head>
            <body>
                <div class="content">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>
            </body>
            </html>
        ';

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}