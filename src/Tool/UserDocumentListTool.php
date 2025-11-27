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
     * 
     * @param string $category Optional: Kategorie zum Filtern (z.B. 'resume', 'contracts', 'other')
     */
    public function __invoke(string $category = ''): array
    {
        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            return [
                'success' => false,
                'message' => 'Benutzer nicht authentifiziert'
            ];
        }

        $documents = empty($category)
            ? $this->documentRepo->findByUser($user)
            : $this->documentRepo->findByUserAndCategory($user, $category);

        $this->logger->info('Documents listed', [
            'user_id' => $user->getId(),
            'category' => $category ?: 'all',
            'count' => count($documents)
        ]);

        return [
            'success' => true,
            'message' => sprintf('%d Dokument(e) gefunden', count($documents)),
            'data' => array_map(fn($doc) => [
                'id' => $doc->getId(),
                'name' => $doc->getDisplayName(),
                'filename' => $doc->getOriginalFilename(),
                'type' => $doc->getDocumentType(),
                'category' => $doc->getCategory(),
                'size' => $doc->getFileSize(),
                'tags' => $doc->getTags(),
                'uploaded_at' => $doc->getCreatedAt()->format('Y-m-d H:i:s'),
                'storage_path' => $doc->getStoragePath(),
            ], $documents)
        ];
    }
}