<?php

namespace App\Repository;

use App\Entity\AgentTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentTask>
 */
class AgentTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTask::class);
    }

    /**
     * Speichert oder aktualisiert eine Task.
     */
    public function save(AgentTask $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Entfernt eine Task.
     */
    public function remove(AgentTask $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    // Fügen Sie hier benutzerdefinierte Repository-Methoden hinzu, z.B. findTasksAwaitingReview()
}