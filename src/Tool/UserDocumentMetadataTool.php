<?php
// src/Tool/UserDocumentMetadataTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Repository\UserDocumentRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'user_document_metadata',
    description: 'Ruft detaillierte Metadaten eines Dokuments ab: Größe, MIME-Typ, Upload-Datum, letzte Änderung, Tags, Kategorie, Prüfsumme und weitere technische Informationen.'
)]
final class UserDocumentMetadataTool
{
    public function __construct(
        private LoggerInterface $logger,
        private UserDocumentRepository $documentRepo,
        private Security $security,
    ) {}

    /**
     * Ruft Metadaten eines Dokuments ab
     * 
     * @param string $identifier Dokumentname oder ID
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
        
        $doc = $this->findDocument($user, $identifier);
        
        if (!$doc) {
            return [
                'success' => false,
                'message' => "Dokument nicht gefunden: '$identifier'"
            ];
        }

        return [
            'success' => true,
            'message' => 'Metadaten erfolgreich abgerufen',
            'data' => [
                'id' => $doc->getId(),
                'name' => $doc->getDisplayName(),
                'filename' => $doc->getOriginalFilename(),
                'type' => $doc->getDocumentType(),
                'mime_type' => $doc->getMimeType(),
                'category' => $doc->getCategory(),
                'size' => $doc->getFileSize(),
                'checksum' => $doc->getChecksum(),
                'tags' => $doc->getTags(),
                'description' => $doc->getDescription(),
                'metadata' => $doc->getMetadata(),
                'created_at' => $doc->getCreatedAt()->format('Y-m-d H:i:s'),
                'updated_at' => $doc->getUpdatedAt()->format('Y-m-d H:i:s'),
                'last_accessed' => $doc->getLastAccessedAt()?->format('Y-m-d H:i:s')
            ]
        ];
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