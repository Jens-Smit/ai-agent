<?php 
// src/Repository/AgentStatusRepository.php

namespace App\Repository;

use App\Entity\AgentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentStatus::class);
    }

    public function deleteOlderThan(\DateTimeInterface $date): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}