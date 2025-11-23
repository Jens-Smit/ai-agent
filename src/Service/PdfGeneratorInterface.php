<?php

declare(strict_types=1);

namespace App\Service;

interface PdfGeneratorInterface
{
    /**
     * Generates a PDF from the given text content and saves it to the specified filename.
     *
     * @param string $text The text content for the PDF.
     * @param string $filename The desired output filename for the PDF (e.g., "document.pdf").
     * @throws \Exception If PDF generation fails.
     */
    public function generate(string $text, string $filename): void;
}
