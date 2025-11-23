<?php
// src/Tool/UserDocumentReadTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Repository\UserDocumentRepository;
use App\Service\UserDocumentService;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Smalot\PdfParser\Parser as PdfParser;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'user_document_read',
    description: 'Liest den Inhalt eines hochgeladenen Dokuments und extrahiert den Text. Unterstützt PDFs, Word-Dokumente (.docx, .doc), Textdateien und mehr. Gibt den kompletten Textinhalt zurück. Ideal für: Dokument analysieren, Zusammenfassungen erstellen, Inhalte weiterverarbeiten.'
)]
final class UserDocumentReadTool
{
    public function __construct(
        private LoggerInterface $logger,
        private UserDocumentRepository $documentRepo,
        private UserDocumentService $documentService,
        private Security $security,
    ) {}

    /**
     * Liest ein Dokument und extrahiert den Textinhalt
     * 
     * @param string $identifier Dokumentname oder ID (z.B. "Lebenslauf" oder "42")
     */
    public function __invoke(string $identifier): array
    {
        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            return [
                'success' => false,
                'message' => 'Benutzer nicht authentifiziert'
            ];
        }

        // Suche nach Name oder ID
        $doc = $this->findDocument($user, $identifier);
        
        if (!$doc) {
            // Liste verfügbare Dokumente zur Hilfe
            $availableDocs = $this->documentRepo->findByUser($user);
            $docNames = array_map(fn($d) => $d->getDisplayName(), $availableDocs);
            
            return [
                'success' => false,
                'message' => "Dokument nicht gefunden: '$identifier'",
                'available_documents' => $docNames,
                'hint' => 'Nutze user_document_list um alle Dokumente zu sehen'
            ];
        }

        // Prüfe ob bereits extrahierter Text vorhanden ist
        $extractedText = $doc->getExtractedText();
        
        if ($extractedText) {
            $this->logger->info('Document read from cache', [
                'document_id' => $doc->getId(),
                'name' => $doc->getDisplayName()
            ]);
            
            return [
                'success' => true,
                'message' => 'Dokument erfolgreich gelesen (aus Cache)',
                'data' => [
                    'id' => $doc->getId(),
                    'name' => $doc->getDisplayName(),
                    'filename' => $doc->getOriginalFilename(),
                    'type' => $doc->getDocumentType(),
                    'size' => $doc->getFileSize(),
                    'content' => $extractedText,
                    'metadata' => $doc->getMetadata()
                ]
            ];
        }

        // Andernfalls: Datei neu lesen
        try {
            $content = $this->documentService->getContent($doc);
            
            // Bei PDFs: Text extrahieren
            if ($doc->getDocumentType() === 'pdf') {
                $parser = new PdfParser();
                $pdf = $parser->parseContent($content);
                $content = $pdf->getText();
            }
            
            $this->logger->info('Document read successfully', [
                'document_id' => $doc->getId(),
                'name' => $doc->getDisplayName(),
                'content_length' => strlen($content)
            ]);
            
            return [
                'success' => true,
                'message' => 'Dokument erfolgreich gelesen',
                'data' => [
                    'id' => $doc->getId(),
                    'name' => $doc->getDisplayName(),
                    'filename' => $doc->getOriginalFilename(),
                    'type' => $doc->getDocumentType(),
                    'size' => $doc->getFileSize(),
                    'content' => $content,
                    'metadata' => $doc->getMetadata()
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to read document content', [
                'document_id' => $doc->getId(),
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Fehler beim Lesen der Datei: ' . $e->getMessage()
            ];
        }
    }

    private function findDocument(User $user, string $identifier): ?object
    {
        // Versuche als ID
        if (is_numeric($identifier)) {
            $doc = $this->documentRepo->find((int)$identifier);
            if ($doc && $doc->getUser() === $user) {
                return $doc;
            }
        }

        // Versuche als Displayname (case-insensitive, partial match)
        $docs = $this->documentRepo->findByUser($user);
        foreach ($docs as $doc) {
            if (stripos($doc->getDisplayName(), $identifier) !== false ||
                stripos($doc->getOriginalFilename(), $identifier) !== false) {
                return $doc;
            }
        }

        return null;
    }
}