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
     * Findet ähnliche Dokumente basierend auf Cosine-Similarity (Vektorsuche).
     *
     * @param array $queryEmbedding Das Query-Embedding als Array von Floats (erwartet 768 Dimensionen).
     * @param int $limit Anzahl der Ergebnisse (Standard: 5).
     * @param float $minScore Minimale Similarity-Score (0.0-1.0) (Standard: 0.3).
     * @return array Array von [document, score] Paaren.
     */
    public function findSimilarFixed(array $queryEmbedding, int $limit = 5, float $minScore = 0.3): array
    {
        if (count($queryEmbedding) !== 768) {
            throw new \InvalidArgumentException('Query embedding must have 768 dimensions for text-embedding-004 model.');
        }

        $conn = $this->getEntityManager()->getConnection();

        // 1. Alle Dokumente abrufen, die überhaupt Embeddings haben
        $sql = "
            SELECT id, embedding_dimension, embedding
            FROM knowledge_documents
            WHERE embedding_dimension = 768
        ";
        $rows = $conn->executeQuery($sql)->fetchAllAssociative();

        $results = [];

        foreach ($rows as $row) {
            $embedding = json_decode($row['embedding'], true);

            if (!is_array($embedding) || count($embedding) !== 768) {
                continue; // skip invalid embeddings
            }

            // 2. Cosine similarity berechnen
            $dot = 0.0;
            $normA = 0.0;
            $normB = 0.0;
            for ($i = 0; $i < 768; $i++) {
                $dot += $queryEmbedding[$i] * $embedding[$i];
                $normA += $queryEmbedding[$i] ** 2;
                $normB += $embedding[$i] ** 2;
            }
            $similarity = $dot / (sqrt($normA) * sqrt($normB));

            if ($similarity >= $minScore) {
                $results[] = [
                    'id' => $row['id'],
                    'score' => $similarity
                ];
            }
        }

        // 3. Nach Score sortieren und limitieren
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        // 4. Entity-Objekte laden
        $documents = [];
        foreach ($results as $r) {
            $doc = $this->find($r['id']);
            if ($doc) {
                $documents[] = [
                    'document' => $doc,
                    'score' => $r['score']
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