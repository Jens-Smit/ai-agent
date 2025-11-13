<?php 
// src/Repository/KnowledgeDocumentRepository.php

namespace App\Repository;

use App\Entity\KnowledgeDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KnowledgeDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KnowledgeDocument::class);
    }

    /**
     * Findet ähnliche Dokumente basierend auf Cosine-Similarity
     * 
     * @param array $queryEmbedding Das Query-Embedding als Array
     * @param int $limit Anzahl der Ergebnisse
     * @param float $minScore Minimale Similarity-Score (0-1)
     * @return array Array von [document, score] Paaren
     */
    public function findSimilar(array $queryEmbedding, int $limit = 5, float $minScore = 0.0): array
    {
        // Baue den Cosine-Similarity SQL-Ausdruck
        $embeddingJson = json_encode($queryEmbedding);
        
        $sql = "
            SELECT 
                k.*,
                (
                    -- Cosine Similarity Berechnung
                    -- dot_product / (magnitude_a * magnitude_b)
                    (
                        SELECT SUM(
                            JSON_EXTRACT(k.embedding, CONCAT('$[', idx.idx, ']')) * 
                            JSON_EXTRACT(:query_embedding, CONCAT('$[', idx.idx, ']'))
                        )
                        FROM (
                            SELECT 0 as idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
                            SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL
                            SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
                            -- Erweitere dies bis zur Embedding-Dimension (z.B. 768 für text-embedding-004)
                            -- Oder verwende eine gespeicherte Prozedur für dynamische Dimensionen
                        ) as idx
                        WHERE idx.idx < k.embedding_dimension
                    ) / (
                        SQRT(
                            (SELECT SUM(
                                POW(JSON_EXTRACT(k.embedding, CONCAT('$[', idx.idx, ']')), 2)
                            ) FROM (SELECT 0 as idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as idx WHERE idx.idx < k.embedding_dimension)
                        ) *
                        SQRT(
                            (SELECT SUM(
                                POW(JSON_EXTRACT(:query_embedding, CONCAT('$[', idx.idx, ']')), 2)
                            ) FROM (SELECT 0 as idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as idx WHERE idx.idx < k.embedding_dimension)
                        )
                    )
                ) as similarity_score
            FROM knowledge_documents k
            HAVING similarity_score >= :min_score
            ORDER BY similarity_score DESC
            LIMIT :limit
        ";

        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare($sql);
        
        $results = $stmt->executeQuery([
            'query_embedding' => $embeddingJson,
            'min_score' => $minScore,
            'limit' => $limit
        ])->fetchAllAssociative();

        $documents = [];
        foreach ($results as $row) {
            $doc = $this->find($row['id']);
            if ($doc) {
                $documents[] = [
                    'document' => $doc,
                    'score' => (float) $row['similarity_score']
                ];
            }
        }

        return $documents;
    }

    /**
     * Löscht alle Dokumente aus einer bestimmten Quelle
     */
    public function deleteBySource(string $sourceFile): int
    {
        return $this->createQueryBuilder('k')
            ->delete()
            ->where('k.sourceFile = :source')
            ->setParameter('source', $sourceFile)
            ->getQuery()
            ->execute();
    }
}