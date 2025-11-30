<?php
// src/Tool/UserDocumentListTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Repository\UserDocumentRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'user_document_list',
    description: 'Lists all non-secret documents of the current user. Can filter by category (e.g., "resume", "cover_letter", "certificate"). Secret documents (isSecret=true) are automatically excluded for privacy. Returns: document ID, filename, display name, category, file size, creation date, and whether text was extracted.'
)]
final class UserDocumentListTool
{
    public function __construct(
        private LoggerInterface $logger,
        private UserDocumentRepository $documentRepo,
        private Security $security,
    ) {}

    /**
     * Listet alle nicht-geheimen Dokumente des Users auf
     * 
     * @param string $category Optional: Filter nach Kategorie (z.B. "resume", "cover_letter")
     * @param bool $includeSecret Optional: Auch geheime Dokumente einbeziehen (default: false)
     */
    public function __invoke(string $category = '', bool $includeSecret = false): array
    {
        $this->logger->info('UserDocumentListTool invoked', [
            'category' => $category ?: 'all',
            'include_secret' => $includeSecret
        ]);

        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            $this->logger->warning('Unauthenticated access attempt to UserDocumentListTool');
            return [
                'success' => false,
                'message' => 'Benutzer nicht authentifiziert'
            ];
        }

        try {
            // 🔒 Standardmäßig nur nicht-geheime Dokumente
            if (!empty($category)) {
                $documents = $this->documentRepo->findByUserAndCategory($user, $category);
                
                $this->logger->debug('Documents filtered by category', [
                    'user_id' => $user->getId(),
                    'category' => $category,
                    'count' => count($documents)
                ]);
            } else {
                $documents = $this->documentRepo->findNonSecretByUser($user);
                
                $this->logger->debug('All non-secret documents loaded', [
                    'user_id' => $user->getId(),
                    'count' => count($documents)
                ]);
            }

            // 🔓 Optional: Auch geheime Dokumente einbeziehen (für Admin/Debug)
            if ($includeSecret && empty($documents)) {
                $this->logger->info('Including secret documents (fallback)', [
                    'user_id' => $user->getId()
                ]);
                
                $documents = !empty($category)
                    ? $this->documentRepo->findBy(['user' => $user, 'category' => $category])
                    : $this->documentRepo->findAllByUser($user);
            }

            $result = array_map(function($doc) {
                return [
                    'id' => $doc->getId(),
                    'filename' => $doc->getOriginalFilename(),
                    'display_name' => $doc->getDisplayName(),
                    'category' => $doc->getCategory(),
                    'mime_type' => $doc->getMimeType(),
                    'type' => $doc->getDocumentType(),
                    'file_size' => $doc->getFileSize(),
                    'created_at' => $doc->getCreatedAt()->format('Y-m-d H:i:s'),
                    'is_secret' => $doc->isSecret(),
                    'has_extracted_text' => !empty($doc->getExtractedText()),
                    'description' => $doc->getDescription(),
                    'tags' => $doc->getTags()
                ];
            }, $documents);

            $this->logger->info('Documents listed successfully', [
                'user_id' => $user->getId(),
                'category' => $category ?: 'all',
                'count' => count($result),
                'includes_secret' => $includeSecret
            ]);

            return [
                'success' => true,
                'message' => sprintf('%d Dokument(e) gefunden', count($result)),
                'count' => count($result),
                'category_filter' => $category ?: null,
                'includes_secret' => $includeSecret,
                'data' => $result
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to list documents', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Fehler beim Laden der Dokumente: ' . $e->getMessage()
            ];
        }
    }
}