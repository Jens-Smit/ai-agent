<?php
// src/Tool/UserDocumentSearchTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Repository\UserDocumentRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'user_document_search',
    description: 'Sucht nach Dokumenten anhand von Suchbegriff. Durchsucht Dokumentname, Beschreibung und Tags. Optional kann zusätzlich nach Kategorie gefiltert werden.'
)]
final class UserDocumentSearchTool
{
    public function __construct(
        private LoggerInterface $logger,
        private UserDocumentRepository $documentRepo,
        private Security $security,
    ) {}

    /**
     * Sucht nach Dokumenten anhand von Suchbegriff und optional Kategorie
     * 
     * @param string $searchTerm Suchbegriff (durchsucht Name, Beschreibung, Tags)
     * @param string $category Optional: Kategorie zum Filtern
     */
    public function __invoke(string $searchTerm, string $category = ''): array
    {
        $user = $this->security->getUser();
        
        if (!$user instanceof User) {
            return [
                'success' => false,
                'message' => 'Benutzer nicht authentifiziert'
            ];
        }

        $documents = $this->documentRepo->searchByUser(
            $user, 
            $searchTerm, 
            empty($category) ? null : $category
        );

        $this->logger->info('Documents searched', [
            'user_id' => $user->getId(),
            'search_term' => $searchTerm,
            'category' => $category ?: 'all',
            'results' => count($documents)
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
                'description' => $doc->getDescription(),
                'tags' => $doc->getTags()
            ], $documents)
        ];
    }
}