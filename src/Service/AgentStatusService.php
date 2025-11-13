<?php
// src/Service/AgentStatusService.php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\AgentStatus;

class AgentStatusService
{
    private array $inMemoryStatuses = [];

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Fügt einen Status zu einer Session hinzu
     */
    public function addStatus(string $sessionId, string $message): void
    {
        $status = new AgentStatus();
        $status->setSessionId($sessionId);
        $status->setMessage($message);
        $status->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($status);
        $this->em->flush();

        // Auch in Memory halten für schnellen Zugriff
        if (!isset($this->inMemoryStatuses[$sessionId])) {
            $this->inMemoryStatuses[$sessionId] = [];
        }

        $this->inMemoryStatuses[$sessionId][] = [
            'timestamp' => $status->getCreatedAt()->format('Y-m-d H:i:s'),
            'message' => $message,
        ];
    }

    /**
     * Holt alle Status-Meldungen für eine Session
     */
    public function getStatuses(string $sessionId): array
    {
        $repository = $this->em->getRepository(AgentStatus::class);
        $statuses = $repository->findBy(
            ['sessionId' => $sessionId],
            ['createdAt' => 'ASC']
        );

        return array_map(function (AgentStatus $status) {
            return [
                'timestamp' => $status->getCreatedAt()->format('Y-m-d H:i:s'),
                'message' => $status->getMessage(),
            ];
        }, $statuses);
    }

    /**
     * Löscht alle Status-Meldungen für eine Session
     */
    public function clearStatuses(string $sessionId): void
    {
        $repository = $this->em->getRepository(AgentStatus::class);
        $statuses = $repository->findBy(['sessionId' => $sessionId]);

        foreach ($statuses as $status) {
            $this->em->remove($status);
        }

        $this->em->flush();

        unset($this->inMemoryStatuses[$sessionId]);
    }

    /**
     * Holt die neuesten Status-Updates (für Polling)
     */
    public function getStatusesSince(string $sessionId, \DateTimeInterface $since): array
    {
        $repository = $this->em->getRepository(AgentStatus::class);
        
        $qb = $repository->createQueryBuilder('s');
        $qb->where('s.sessionId = :sessionId')
           ->andWhere('s.createdAt > :since')
           ->setParameter('sessionId', $sessionId)
           ->setParameter('since', $since)
           ->orderBy('s.createdAt', 'ASC');

        $statuses = $qb->getQuery()->getResult();

        return array_map(function (AgentStatus $status) {
            return [
                'timestamp' => $status->getCreatedAt()->format('Y-m-d H:i:s'),
                'message' => $status->getMessage(),
            ];
        }, $statuses);
    }
}