<?php
// src/Repository/UserKnowledgeDocumentRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserKnowledgeDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserKnowledgeDocument>
 */
class UserKnowledgeDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserKnowledgeDocument::class);
    }

    /**
     * Findet ähnliche Dokumente für einen User basierend auf Cosine-Similarity
     * 
     * @param User $user Der Benutzer
     * @param array $queryEmbedding Das Query-Embedding als Array
     * @param int $limit Anzahl der Ergebnisse
     * @param float $minScore Minimale Similarity-Score (0-1)
     * @return array Array von [document, score] Paaren
     */
    public function findSimilarForUser(
        User $user,
        array $queryEmbedding,
        int $limit = 5,
        float $minScore = 0.3
    ): array {
        // Hole alle aktiven Dokumente des Users
        $documents = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        if (empty($documents)) {
            return [];
        }

        // Berechne Cosine-Similarity in PHP (für MySQL ohne Vektor-Extension)
        $results = [];
        $queryMagnitude = $this->calculateMagnitude($queryEmbedding);

        foreach ($documents as $doc) {
            $docEmbedding = $doc->getEmbedding();
            
            if (empty($docEmbedding)) {
                continue;
            }

            $dotProduct = $this->calculateDotProduct($queryEmbedding, $docEmbedding);
            $docMagnitude = $this->calculateMagnitude($docEmbedding);

            if ($queryMagnitude > 0 && $docMagnitude > 0) {
                $similarity = $dotProduct / ($queryMagnitude * $docMagnitude);
                
                if ($similarity >= $minScore) {
                    $results[] = [
                        'document' => $doc,
                        'score' => $similarity
                    ];
                }
            }
        }

        // Sortiere nach Score (absteigend)
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limitiere Ergebnisse
        return array_slice($results, 0, $limit);
    }

    /**
     * Findet alle Dokumente eines Users nach Tags
     */
    public function findByUserAndTags(User $user, array $tags): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true);

        // JSON-Suche nach Tags
        foreach ($tags as $i => $tag) {
            $qb->andWhere("JSON_CONTAINS(d.tags, :tag{$i}) = 1")
               ->setParameter("tag{$i}", json_encode($tag));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Findet Dokumente nach Quelltyp
     */
    public function findByUserAndSourceType(User $user, string $sourceType): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.sourceType = :sourceType')
            ->andWhere('d.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('sourceType', $sourceType)
            ->setParameter('active', true)
            ->orderBy('d.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Zählt Dokumente eines Users
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.user = :user')
            ->andWhere('d.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Löscht alle Dokumente eines Users
     */
    public function deleteByUser(User $user): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Berechnet das Skalarprodukt zweier Vektoren
     */
    private function calculateDotProduct(array $a, array $b): float
    {
        $sum = 0.0;
        $length = min(count($a), count($b));
        
        for ($i = 0; $i < $length; $i++) {
            $sum += ($a[$i] ?? 0) * ($b[$i] ?? 0);
        }
        
        return $sum;
    }

    /**
     * Berechnet die Magnitude (Länge) eines Vektors
     */
    private function calculateMagnitude(array $vector): float
    {
        $sum = 0.0;
        
        foreach ($vector as $value) {
            $sum += $value * $value;
        }
        
        return sqrt($sum);
    }

    /**
     * Sucht nach Dokumenten mit Volltextsuche im Content
     */
    public function searchByContent(User $user, string $searchTerm, int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.isActive = :active')
            ->andWhere('d.content LIKE :search OR d.title LIKE :search')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->setParameter('search', '%' . $searchTerm . '%')
            ->orderBy('d.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}