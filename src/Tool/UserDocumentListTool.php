<?php
// src/Tool/UserDocumentListTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Repository\UserDocumentRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;
use Throwable; // Importiere die Throwable-Klasse, um alle Fehler abzufangen

#[AsTool(
    name: 'user_document_list',
    description: 'Listet alle hochgeladenen Dokumente des Benutzers auf. Optional kann nach Kategorie gefiltert werden (z.B. "resume", "contracts", "other"). Gibt Name, Typ, Größe und Upload-Datum aller Dokumente zurück.'
)]
final class UserDocumentListTool
{
    public function __construct(
        private LoggerInterface $logger,
        private UserDocumentRepository $documentRepo,
        private Security $security,
    ) {}

    /**
     * Listet alle Dokumente auf, optional gefiltert nach Kategorie
     * * @param string $category Optional: Kategorie zum Filtern (z.B. 'resume', 'contracts', 'other')
     */
    public function __invoke(string $category = ''): array
    {
        // 1. Benutzer-Validierung und Logging
        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            $this->logger->warning('UserDocumentListTool called without authenticated User.');
            return [
                'success' => false,
                'message' => 'Benutzer nicht authentifiziert'
            ];
        }

        $userId = $user->getId();
        $this->logger->info('UserDocumentListTool: Start listing documents.', [
            'user_id' => $userId, 
            'requested_category' => $category ?: 'all'
        ]);

        try {
            // 2. Datenbank-Abfrage
            $documents = empty($category)
                ? $this->documentRepo->findByUser($user)
                : $this->documentRepo->findByUserAndCategory($user, $category);
            
            $count = count($documents);
            $this->logger->info('UserDocumentListTool: Database query successful.', [
                'user_id' => $userId,
                'count' => $count
            ]);

            // 3. Daten-Mapping und Verarbeitung
            $mappedData = array_map(function ($doc) {
                // Zusätzlicher interner Log für die erfolgreiche Mappung
                $this->logger->debug('UserDocumentListTool: Mapping document.', ['doc_id' => $doc->getId()]);

                // HINWEIS: Prüfe hier, ob $doc->getCreatedAt() null sein kann.
                // Wenn ja, muss es vor dem Aufruf von format() geprüft werden.
                // Beispiel: $doc->getCreatedAt() ? $doc->getCreatedAt()->format('Y-m-d H:i:s') : null
                
                return [
                    'id' => $doc->getId(),
                    'name' => $doc->getDisplayName(),
                    'filename' => $doc->getOriginalFilename(),
                    'type' => $doc->getDocumentType(),
                    'category' => $doc->getCategory(),
                    'size' => $doc->getFileSize(),
                    'tags' => $doc->getTags(),
                    'uploaded_at' => $doc->getCreatedAt() ? $doc->getCreatedAt()->format('Y-m-d H:i:s') : null, // Added null check
                    'storage_path' => $doc->getStoragePath(),
                ];
            }, $documents);

            // 4. Erfolgreiche Rückgabe
            $this->logger->info('UserDocumentListTool: Successfully finished.', ['user_id' => $userId]);

            return [
                'success' => true,
                'message' => sprintf('%d Dokument(e) gefunden', $count),
                'data' => $mappedData
            ];

        } catch (Throwable $e) {
            // 5. Fehlerbehandlung und Logging
            $errorId = uniqid('DOC_ERR_');
            $this->logger->error('UserDocumentListTool: CRITICAL FAILURE!', [
                'error_id' => $errorId,
                'user_id' => $userId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'category' => $category
            ]);

            // Strukturierte Fehlerantwort an den Agenten zurückgeben
            return [
                'success' => false,
                'message' => 'Ein kritischer interner Fehler ist bei der Dokumentensuche aufgetreten. Bitte überprüfen Sie das Server-Log. (Ref: ' . $errorId . ')',
                'error_type' => get_class($e)
            ];
        }
    }
}