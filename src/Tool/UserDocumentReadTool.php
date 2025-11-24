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
        $this->logger->info('UserDocumentReadTool invoked', [
            'identifier' => $identifier,
        ]);

        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            $this->logger->warning('Unauthenticated access attempt to UserDocumentReadTool', [
                'identifier' => $identifier,
            ]);
            return [
                'success' => false,
                'message' => 'Benutzer nicht authentifiziert'
            ];
        }

        $this->logger->info('Searching for document', [
            'user_id' => $user->getId(),
            'identifier' => $identifier,
        ]);

        // Suche nach Name oder ID
        $doc = $this->findDocument($user, $identifier);
        
        if (!$doc) {
            $this->logger->warning('Document not found', [
                'user_id' => $user->getId(),
                'identifier' => $identifier,
            ]);

            // Liste verfügbare Dokumente zur Hilfe
            $availableDocs = $this->documentRepo->findByUser($user);
            $docNames = array_map(fn($d) => $d->getDisplayName(), $availableDocs);
            
            $this->logger->info('Available documents for user', [
                'user_id' => $user->getId(),
                'documents' => $docNames,
            ]);

            return [
                'success' => false,
                'message' => "Dokument nicht gefunden: '$identifier'",
                'available_documents' => $docNames,
                'hint' => 'Nutze user_document_list um alle Dokumente zu sehen'
            ];
        }

        $this->logger->info('Document found', [
            'document_id' => $doc->getId(),
            'name' => $doc->getDisplayName(),
            'filename' => $doc->getOriginalFilename(),
            'type' => $doc->getDocumentType(),
            'size' => $doc->getFileSize(),
        ]);

        // Prüfe ob bereits extrahierter Text vorhanden ist
        $extractedText = $doc->getExtractedText();
        
        if ($extractedText) {
            $this->logger->info('Document read from cache', [
                'document_id' => $doc->getId(),
                'name' => $doc->getDisplayName(),
                'content_length' => strlen($extractedText),
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
            $this->logger->info('Reading document content from storage', [
                'document_id' => $doc->getId(),
                'name' => $doc->getDisplayName(),
            ]);

            $content = $this->documentService->getContent($doc);
            
            // Bei PDFs: Text extrahieren
            if ($doc->getDocumentType() === 'pdf') {
                $this->logger->debug('Parsing PDF document', [
                    'document_id' => $doc->getId(),
                ]);
                $parser = new PdfParser();
                $pdf = $parser->parseContent($content);
                $content = $pdf->getText();
                $this->logger->debug('PDF parsing completed', [
                    'document_id' => $doc->getId(),
                    'content_length' => strlen($content),
                ]);
            }
            
            $this->logger->info('Document read successfully', [
                'document_id' => $doc->getId(),
                'name' => $doc->getDisplayName(),
                'content_length' => strlen($content),
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
                'name' => $doc->getDisplayName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Fehler beim Lesen der Datei: ' . $e->getMessage()
            ];
        }
    }

    private function findDocument(User $user, string $identifier): ?object
    {
        $this->logger->debug('Attempting to find document', [
            'user_id' => $user->getId(),
            'identifier' => $identifier,
        ]);

        // Versuche als ID
        if (is_numeric($identifier)) {
            $doc = $this->documentRepo->find((int)$identifier);
            if ($doc && $doc->getUser() === $user) {
                $this->logger->debug('Document found by ID', [
                    'document_id' => $doc->getId(),
                ]);
                return $doc;
            }
        }

        // Versuche als Displayname (case-insensitive, partial match)
        $docs = $this->documentRepo->findByUser($user);
        foreach ($docs as $doc) {
            if (stripos($doc->getDisplayName(), $identifier) !== false ||
                stripos($doc->getOriginalFilename(), $identifier) !== false) {
                $this->logger->debug('Document found by name match', [
                    'document_id' => $doc->getId(),
                    'name' => $doc->getDisplayName(),
                ]);
                return $doc;
            }
        }

        $this->logger->debug('No document matched identifier', [
            'user_id' => $user->getId(),
            'identifier' => $identifier,
        ]);

        return null;
    }
}
