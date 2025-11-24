<?php
// src/Repository/TokenUsageRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TokenUsage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TokenUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TokenUsage::class);
    }

    /**
     * Holt Gesamtnutzung in einem Zeitraum
     */
    public function getUsageInTimeframe(
        User $user,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): int {
        $qb = $this->createQueryBuilder('t');
        
        $result = $qb->select('SUM(t.totalTokens)')
            ->where('t.user = :user')
            ->andWhere('t.timestamp >= :start')
            ->andWhere('t.timestamp <= :end')
            ->andWhere('t.success = true')
            ->setParameter('user', $user)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Holt detaillierte Usage-Breakdown nach Model
     */
    public function getUsageBreakdown(
        User $user,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null
    ): array {
        $qb = $this->createQueryBuilder('t');
        
        $qb->select(
            't.model',
            't.agentType',
            'SUM(t.inputTokens) as input_tokens',
            'SUM(t.outputTokens) as output_tokens',
            'SUM(t.totalTokens) as total_tokens',
            'SUM(t.costCents) as cost_cents',
            'COUNT(t.id) as request_count',
            'AVG(t.responseTimeMs) as avg_response_time'
        )
        ->where('t.user = :user')
        ->andWhere('t.success = true')
        ->setParameter('user', $user)
        ->groupBy('t.model', 't.agentType');

        if ($startDate) {
            $qb->andWhere('t.timestamp >= :start')
               ->setParameter('start', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('t.timestamp <= :end')
               ->setParameter('end', $endDate);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Holt Nutzung pro Tag für Charts
     */
    public function getDailyUsage(
        User $user,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): array {
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = "
            SELECT 
                DATE(timestamp) as date,
                SUM(total_tokens) as tokens,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(cost_cents) as cost_cents,
                COUNT(*) as requests
            FROM token_usage
            WHERE user_id = :userId
                AND timestamp >= :startDate
                AND timestamp <= :endDate
                AND success = 1
            GROUP BY DATE(timestamp)
            ORDER BY date ASC
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'userId' => $user->getId(),
            'startDate' => $startDate->format('Y-m-d H:i:s'),
            'endDate' => $endDate->format('Y-m-d H:i:s')
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Holt Top N Sessions nach Token-Nutzung
     */
    public function getTopSessions(
        User $user,
        int $limit = 10,
        ?\DateTimeImmutable $since = null
    ): array {
        $qb = $this->createQueryBuilder('t');
        
        $qb->select(
            't.sessionId',
            'SUM(t.totalTokens) as total_tokens',
            'MIN(t.timestamp) as started_at',
            'MAX(t.timestamp) as ended_at',
            'COUNT(t.id) as request_count'
        )
        ->where('t.user = :user')
        ->andWhere('t.sessionId IS NOT NULL')
        ->andWhere('t.success = true')
        ->setParameter('user', $user)
        ->groupBy('t.sessionId')
        ->orderBy('total_tokens', 'DESC')
        ->setMaxResults($limit);

        if ($since) {
            $qb->andWhere('t.timestamp >= :since')
               ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Holt fehlgeschlagene Requests
     */
    public function getFailedRequests(
        User $user,
        ?\DateTimeImmutable $since = null,
        int $limit = 50
    ): array {
        $qb = $this->createQueryBuilder('t');
        
        $qb->where('t.user = :user')
            ->andWhere('t.success = false')
            ->setParameter('user', $user)
            ->orderBy('t.timestamp', 'DESC')
            ->setMaxResults($limit);

        if ($since) {
            $qb->andWhere('t.timestamp >= :since')
               ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Holt aktuelle Nutzung (letzte N Minuten)
     */
    public function getCurrentUsage(User $user, int $minutes = 5): array
    {
        $since = new \DateTimeImmutable("-{$minutes} minutes");
        
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.timestamp >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('t.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Bereinigt alte Einträge (für Maintenance)
     */
    public function cleanupOldEntries(int $daysToKeep = 90): int
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysToKeep} days");
        
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.timestamp < :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}