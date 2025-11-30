<?php
// src/Repository/UserDocumentRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDocument>
 */
class UserDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDocument::class);
    }

    /**
     * Findet alle Dokumente eines Users
     */
    public function findByUser(User $user, ?string $category = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($category !== null) {
            $qb->andWhere('d.category = :category')
               ->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Findet Dokumente nach Typ (pdf, image, document, etc.)
     */
    public function findByUserAndType(User $user, string $documentType): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.documentType = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $documentType)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
    public function findByUserAndCategory(User $user, string $category): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.category = :category')
            ->andWhere('d.isSecret = false')  // ✅ Nur nicht-geheime Dokumente
            ->setParameter('user', $user)
            ->setParameter('category', $category)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
    /**
     * Findet Dokumente nach Tags
     */
    public function findByUserAndTags(User $user, array $tags): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->setParameter('user', $user);

        foreach ($tags as $i => $tag) {
            $qb->andWhere("JSON_CONTAINS(d.tags, :tag{$i}) = 1")
               ->setParameter("tag{$i}", json_encode($tag));
        }

        return $qb->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
    /**
     * Findet alle nicht-geheimen Dokumente eines Users
     */
    public function findNonSecretByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.isSecret = false')  // ✅ Nur nicht-geheime Dokumente
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet ALLE Dokumente eines Users (inkl. geheime) - für Admin/Debug
     */
    public function findAllByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Zählt nicht-geheime Dokumente nach Kategorie
     */
    public function countByUserAndCategory(User $user, string $category): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.user = :user')
            ->andWhere('d.category = :category')
            ->andWhere('d.isSecret = false')
            ->setParameter('user', $user)
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }
    /**
     * Sucht in Dokumenten (Name, Beschreibung, extrahierter Text)
     */
    public function searchByUser(User $user, string $searchTerm, ?string $category, int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere(
                'd.originalFilename LIKE :search OR ' .
                'd.displayName LIKE :search OR ' .
                'd.description LIKE :search OR ' .
                'd.extractedText LIKE :search'
            )
            ->setParameter('user', $user)
            ->setParameter('search', '%' . $searchTerm . '%')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet Vorlagen (Templates) eines Users
     */
    public function findTemplates(User $user): array
    {
        return $this->findByUser($user, UserDocument::CATEGORY_TEMPLATE, 100);
    }

    /**
     * Findet ein Dokument per Checksum (Duplikat-Erkennung)
     */
    public function findByUserAndChecksum(User $user, string $checksum): ?UserDocument
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.checksum = :checksum')
            ->setParameter('user', $user)
            ->setParameter('checksum', $checksum)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Berechnet Speicherverbrauch eines Users
     */
    public function calculateStorageUsage(User $user): int
    {
        $result = $this->createQueryBuilder('d')
            ->select('SUM(d.fileSize) as total')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Zählt Dokumente eines Users
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Findet Dokumente die noch nicht indiziert wurden
     */
    public function findUnindexedByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.isIndexed = :indexed')
            ->andWhere('d.extractedText IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('indexed', false)
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet kürzlich zugegriffene Dokumente
     */
    public function findRecentlyAccessed(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.lastAccessedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('d.lastAccessedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
}