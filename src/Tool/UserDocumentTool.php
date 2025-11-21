<?php
// src/Tool/UserDocumentTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\UserDocument;
use App\Repository\UserDocumentRepository;
use App\Repository\UserRepository;
use App\Service\UserDocumentService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;

/**
 * Ermöglicht dem Personal Assistant Zugriff auf die Dokumente des Users
 */
#[AsTool(
    name: 'user_documents',
    description: 'Access and manage the user\'s uploaded documents. Can list, search, and retrieve document content. Use this to find templates, attachments, or reference documents the user has uploaded.'
)]
final class UserDocumentTool
{
    public function __construct(
        private UserDocumentService $documentService,
        private UserDocumentRepository $documentRepo,
        private UserRepository $userRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Listet, sucht oder holt Dokumente des Users
     *
     * @param string $action Die Aktion: list, search, get, get_content
     * @param string|null $query Suchbegriff (für action=search)
     * @param int|null $documentId Dokument-ID (für action=get, get_content)
     * @param string|null $category Filter nach Kategorie (template, attachment, reference, media)
     * @param string|null $documentType Filter nach Typ (pdf, document, image, text)
     * @param int $limit Maximale Anzahl Ergebnisse
     * @return array Dokument(e) oder Statusmeldung
     */
    public function __invoke(
        #[With(enum: ['list', 'search', 'get', 'get_content'])]
        string $action,
        string $query = '',
        string $documentId = '',
        #[With(enum: ['template', 'attachment', 'reference', 'media', 'other', ''])]
        string $category = '',
        #[With(enum: ['pdf', 'document', 'spreadsheet', 'image', 'text', 'other', ''])]
        string $documentType = '',
        #[With(minimum: 1, maximum: 50)]
        int $limit = 20
    ): array {
        $userId = $GLOBALS['current_user_id'] ?? null;

        if (!$userId) {
            return [
                'status' => 'error',
                'message' => 'No user context available.'
            ];
        }

        $user = $this->userRepository->find($userId);
        
        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'User not found.'
            ];
        }

        $this->logger->info('UserDocumentTool called', [
            'userId' => $userId,
            'action' => $action,
            'query' => $query,
            'documentId' => $documentId
        ]);

        try {
            return match($action) {
                'list' => $this->listDocuments($user, $category, $documentType, $limit),
                'search' => $this->searchDocuments($user, $query ?? '', $limit),
                'get' => $this->getDocument($user, $documentId),
                'get_content' => $this->getDocumentContent($user, $documentId),
                default => ['status' => 'error', 'message' => 'Unknown action']
            };

        } catch (\Exception $e) {
            $this->logger->error('UserDocumentTool error', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    private function listDocuments($user, ?string $category, ?string $type, int $limit): array
    {
        $documents = $this->documentRepo->findByUser($user, $category, $limit);

        if ($type !== null) {
            $documents = array_filter(
                $documents,
                fn($d) => $d->getDocumentType() === $type
            );
        }

        return [
            'status' => 'success',
            'count' => count($documents),
            'documents' => array_map(fn($d) => $this->formatDocument($d), $documents)
        ];
    }

    private function searchDocuments($user, string $query, int $limit): array
    {
        if (empty(trim($query))) {
            return [
                'status' => 'error',
                'message' => 'Search query cannot be empty'
            ];
        }

        $documents = $this->documentRepo->searchByUser($user, $query, $limit);

        return [
            'status' => 'success',
            'query' => $query,
            'count' => count($documents),
            'documents' => array_map(fn($d) => $this->formatDocument($d), $documents)
        ];
    }

    private function getDocument($user, ?int $documentId): array
    {
        if ($documentId === null) {
            return [
                'status' => 'error',
                'message' => 'Document ID is required'
            ];
        }

        $doc = $this->documentRepo->find($documentId);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return [
                'status' => 'error',
                'message' => 'Document not found or access denied'
            ];
        }

        return [
            'status' => 'success',
            'document' => $this->formatDocument($doc, true)
        ];
    }

    private function getDocumentContent($user, ?int $documentId): array
    {
        if ($documentId === null) {
            return [
                'status' => 'error',
                'message' => 'Document ID is required'
            ];
        }

        $doc = $this->documentRepo->find($documentId);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return [
                'status' => 'error',
                'message' => 'Document not found or access denied'
            ];
        }

        // Für Text-basierte Dokumente: Extrahierten Text zurückgeben
        $extractedText = $doc->getExtractedText();
        
        if ($extractedText) {
            // Kürze wenn zu lang
            $maxLength = 10000;
            $truncated = mb_strlen($extractedText) > $maxLength;
            
            return [
                'status' => 'success',
                'document_id' => $doc->getId(),
                'filename' => $doc->getOriginalFilename(),
                'content_type' => 'text',
                'content' => $truncated 
                    ? mb_substr($extractedText, 0, $maxLength) 
                    : $extractedText,
                'truncated' => $truncated,
                'total_length' => mb_strlen($extractedText)
            ];
        }

        // Für Bilder: Base64 oder Hinweis
        if ($doc->getDocumentType() === UserDocument::TYPE_IMAGE) {
            return [
                'status' => 'success',
                'document_id' => $doc->getId(),
                'filename' => $doc->getOriginalFilename(),
                'content_type' => 'image',
                'message' => 'Image content available. Use download endpoint to retrieve.',
                'metadata' => $doc->getMetadata()
            ];
        }

        return [
            'status' => 'success',
            'document_id' => $doc->getId(),
            'filename' => $doc->getOriginalFilename(),
            'content_type' => 'binary',
            'message' => 'Binary content. No text extraction available.',
            'file_size' => $doc->getHumanReadableSize()
        ];
    }

    private function formatDocument(UserDocument $doc, bool $includeDetails = false): array
    {
        $data = [
            'id' => $doc->getId(),
            'name' => $doc->getDisplayName() ?? $doc->getOriginalFilename(),
            'original_filename' => $doc->getOriginalFilename(),
            'type' => $doc->getDocumentType(),
            'category' => $doc->getCategory(),
            'size' => $doc->getHumanReadableSize(),
            'mime_type' => $doc->getMimeType(),
            'created_at' => $doc->getCreatedAt()->format('Y-m-d H:i'),
            'tags' => $doc->getTags()
        ];

        if ($includeDetails) {
            $data['description'] = $doc->getDescription();
            $data['metadata'] = $doc->getMetadata();
            $data['is_indexed'] = $doc->isIndexed();
            $data['has_extracted_text'] = !empty($doc->getExtractedText());
            $data['access_count'] = $doc->getAccessCount();
            $data['last_accessed'] = $doc->getLastAccessedAt()?->format('Y-m-d H:i');
        }

        return $data;
    }
}