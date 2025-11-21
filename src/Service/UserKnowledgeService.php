<?php
// src/Service/UserKnowledgeService.php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserDocument;
use App\Entity\UserKnowledgeDocument;
use App\Repository\UserKnowledgeDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\Component\Uid\Uuid;

/**
 * Service für benutzerspezifische Knowledge Base
 * Verwaltet Wissensdokumente mit Vektor-Embeddings pro User
 */
class UserKnowledgeService
{
    private const CHUNK_SIZE = 1000;
    private const CHUNK_OVERLAP = 200;
    private const EMBEDDING_MODEL = 'text-embedding-004';

    public function __construct(
        private EntityManagerInterface $em,
        private UserKnowledgeDocumentRepository $knowledgeRepo,
        private PlatformInterface $platform,
        private LoggerInterface $logger
    ) {}

    /**
     * Fügt ein manuelles Wissensdokument hinzu
     */
    public function addManualKnowledge(
        User $user,
        string $title,
        string $content,
        ?array $tags = null,
        ?array $metadata = null
    ): UserKnowledgeDocument {
        $this->logger->info('Adding manual knowledge document', [
            'userId' => $user->getId(),
            'title' => $title
        ]);

        // Erstelle Embedding
        $embedding = $this->createEmbedding($content);

        $doc = new UserKnowledgeDocument();
        $doc->setId(Uuid::v4()->toRfc4122());
        $doc->setUser($user);
        $doc->setTitle($title);
        $doc->setContent($content);
        $doc->setSourceType('manual');
        $doc->setEmbedding($embedding);
        $doc->setTags($tags);
        $doc->setMetadata($metadata);

        $this->em->persist($doc);
        $this->em->flush();

        $this->logger->info('Manual knowledge document added', [
            'docId' => $doc->getId()
        ]);

        return $doc;
    }

    /**
     * Indiziert ein hochgeladenes Dokument in die Knowledge Base
     */
    public function indexUploadedDocument(
        User $user,
        UserDocument $uploadedDoc,
        ?array $tags = null
    ): array {
        $this->logger->info('Indexing uploaded document', [
            'userId' => $user->getId(),
            'documentId' => $uploadedDoc->getId(),
            'filename' => $uploadedDoc->getOriginalFilename()
        ]);

        $extractedText = $uploadedDoc->getExtractedText();
        
        if (empty($extractedText)) {
            throw new \RuntimeException('Document has no extracted text to index');
        }

        // Teile Text in Chunks
        $chunks = $this->splitIntoChunks($extractedText);
        $createdDocs = [];

        foreach ($chunks as $index => $chunk) {
            $embedding = $this->createEmbedding($chunk);

            $doc = new UserKnowledgeDocument();
            $doc->setId(Uuid::v4()->toRfc4122());
            $doc->setUser($user);
            $doc->setTitle(sprintf('%s (Teil %d)', $uploadedDoc->getDisplayName() ?? $uploadedDoc->getOriginalFilename(), $index + 1));
            $doc->setContent($chunk);
            $doc->setSourceType('uploaded_file');
            $doc->setSourceReference($uploadedDoc->getOriginalFilename());
            $doc->setEmbedding($embedding);
            $doc->setTags($tags);
            $doc->setMetadata([
                'uploaded_document_id' => $uploadedDoc->getId(),
                'chunk_index' => $index,
                'total_chunks' => count($chunks)
            ]);

            $this->em->persist($doc);
            $createdDocs[] = $doc;
        }

        // Markiere Dokument als indiziert
        $uploadedDoc->setIsIndexed(true);
        $uploadedDoc->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        $this->logger->info('Document indexed successfully', [
            'documentId' => $uploadedDoc->getId(),
            'chunksCreated' => count($createdDocs)
        ]);

        return $createdDocs;
    }

    /**
     * Indiziert Inhalt von einer URL
     */
    public function indexFromUrl(
        User $user,
        string $url,
        string $content,
        string $title,
        ?array $tags = null
    ): array {
        $this->logger->info('Indexing content from URL', [
            'userId' => $user->getId(),
            'url' => $url
        ]);

        $chunks = $this->splitIntoChunks($content);
        $createdDocs = [];

        foreach ($chunks as $index => $chunk) {
            $embedding = $this->createEmbedding($chunk);

            $doc = new UserKnowledgeDocument();
            $doc->setId(Uuid::v4()->toRfc4122());
            $doc->setUser($user);
            $doc->setTitle(sprintf('%s (Teil %d)', $title, $index + 1));
            $doc->setContent($chunk);
            $doc->setSourceType('url');
            $doc->setSourceReference($url);
            $doc->setEmbedding($embedding);
            $doc->setTags($tags);
            $doc->setMetadata([
                'url' => $url,
                'chunk_index' => $index,
                'total_chunks' => count($chunks),
                'indexed_at' => (new \DateTimeImmutable())->format('c')
            ]);

            $this->em->persist($doc);
            $createdDocs[] = $doc;
        }

        $this->em->flush();

        return $createdDocs;
    }

    /**
     * Sucht in der Knowledge Base eines Users
     */
    public function search(
        User $user,
        string $query,
        int $limit = 5,
        float $minScore = 0.3
    ): array {
        $this->logger->info('Searching user knowledge base', [
            'userId' => $user->getId(),
            'query' => substr($query, 0, 100)
        ]);

        $queryEmbedding = $this->createEmbedding($query);

        return $this->knowledgeRepo->findSimilarForUser(
            $user,
            $queryEmbedding,
            $limit,
            $minScore
        );
    }

    /**
     * Aktualisiert ein Wissensdokument
     */
    public function updateKnowledge(
        UserKnowledgeDocument $doc,
        ?string $title = null,
        ?string $content = null,
        ?array $tags = null
    ): UserKnowledgeDocument {
        if ($title !== null) {
            $doc->setTitle($title);
        }

        if ($content !== null) {
            $doc->setContent($content);
            // Neues Embedding erstellen
            $doc->setEmbedding($this->createEmbedding($content));
        }

        if ($tags !== null) {
            $doc->setTags($tags);
        }

        $doc->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $doc;
    }

    /**
     * Löscht ein Wissensdokument
     */
    public function deleteKnowledge(UserKnowledgeDocument $doc): void
    {
        $doc->setIsActive(false);
        $doc->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Löscht Wissensdokumente permanent
     */
    public function hardDeleteKnowledge(UserKnowledgeDocument $doc): void
    {
        $this->em->remove($doc);
        $this->em->flush();
    }

    /**
     * Holt alle Wissensdokumente eines Users
     */
    public function getAllForUser(User $user): array
    {
        return $this->knowledgeRepo->findBy(
            ['user' => $user, 'isActive' => true],
            ['updatedAt' => 'DESC']
        );
    }

    /**
     * Holt Statistiken für einen User
     */
    public function getStats(User $user): array
    {
        $total = $this->knowledgeRepo->countByUser($user);
        
        $bySourceType = [];
        foreach (['manual', 'uploaded_file', 'url', 'api'] as $type) {
            $docs = $this->knowledgeRepo->findByUserAndSourceType($user, $type);
            $bySourceType[$type] = count($docs);
        }

        return [
            'total_documents' => $total,
            'by_source_type' => $bySourceType
        ];
    }

    /**
     * Erstellt ein Embedding für den gegebenen Text
     */
    private function createEmbedding(string $text): array
    {
        try {
            $result = $this->platform->invoke(self::EMBEDDING_MODEL, $text);
            $vectors = $result->asVectors();

            if (empty($vectors)) {
                throw new \RuntimeException('No vectors returned from embedding model');
            }

            return $vectors[0]->getData();
        } catch (\Exception $e) {
            $this->logger->error('Failed to create embedding', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Teilt Text in überlappende Chunks
     */
    private function splitIntoChunks(string $text): array
    {
        $chunks = [];
        $textLength = mb_strlen($text);
        $position = 0;

        while ($position < $textLength) {
            $chunk = mb_substr($text, $position, self::CHUNK_SIZE);
            
            // Versuche an einem Satzende oder Absatz zu schneiden
            if ($position + self::CHUNK_SIZE < $textLength) {
                $lastPeriod = mb_strrpos($chunk, '.');
                $lastNewline = mb_strrpos($chunk, "\n");
                
                $breakPoint = max($lastPeriod, $lastNewline);
                
                if ($breakPoint !== false && $breakPoint > self::CHUNK_SIZE * 0.5) {
                    $chunk = mb_substr($chunk, 0, $breakPoint + 1);
                }
            }

            $chunks[] = trim($chunk);
            $position += mb_strlen($chunk) - self::CHUNK_OVERLAP;
            
            if ($position < 0) {
                $position = mb_strlen($chunk);
            }
        }

        return array_filter($chunks, fn($c) => !empty(trim($c)));
    }
}