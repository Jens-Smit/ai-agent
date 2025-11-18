<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workflow::class);
    }

    public function findBySessionId(string $sessionId): ?Workflow
    {
        return $this->findOneBy(['sessionId' => $sessionId], ['createdAt' => 'DESC']);
    }

    public function findWaitingForConfirmation(): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.status = :status')
            ->setParameter('status', 'waiting_confirmation')
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}