<?php
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

        // Read-through In-Memory cache
        if (!isset($this->inMemoryStatuses[$sessionId])) {
            $this->inMemoryStatuses[$sessionId] = [];
        }

        $this->inMemoryStatuses[$sessionId][] = [
            'timestamp' => $status->getCreatedAt()->format('c'),
            'message' => $message,
        ];
    }

    /**
     * Holt alle Status-Meldungen für eine Session
     */
    public function getStatuses(string $sessionId): array
    {
        // Wenn Cache vorhanden, nutze den (Quelle-of-truth bleibt DB)
        if (isset($this->inMemoryStatuses[$sessionId])) {
            return $this->inMemoryStatuses[$sessionId];
        }

        $repository = $this->em->getRepository(AgentStatus::class);
        $statuses = $repository->findBy(
            ['sessionId' => $sessionId],
            ['createdAt' => 'ASC']
        );

        $rows = array_map(function (AgentStatus $status) {
            return [
                'timestamp' => $status->getCreatedAt()->format('c'),
                'message' => $status->getMessage(),
            ];
        }, $statuses);

        // populate cache for faster subsequent reads
        $this->inMemoryStatuses[$sessionId] = $rows;

        return $rows;
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
                'timestamp' => $status->getCreatedAt()->format('c'),
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
     * Liefert die IDs aller Sessions (optionales Limit)
     */
    public function getAllSessionIds(int $limit = 1000): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT DISTINCT session_id FROM agent_status ORDER BY MAX(created_at) DESC LIMIT :limit';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->executeQuery();

        return $stmt->fetchFirstColumn();
    }

    /**
     * Liefert aktive Session IDs.
     * "Aktiv" bedeutet: letzter Eintrag neuer als $activeWithin und kein terminaler Marker.
     */
    public function getActiveSessionIds(\DateInterval $activeWithin = null): array
    {
        $activeWithin = $activeWithin ?? new \DateInterval('PT15M'); // default 15 Minuten
        $cutoff = (new \DateTimeImmutable())->sub($activeWithin);

        // Query: Sessions mit last_seen > cutoff
        $conn = $this->em->getConnection();
        // DB-spezifische Funktion für timestamp subtraction kann angepasst werden; hier ANSI SQL-Style
        $sql = "
            SELECT s.session_id
            FROM agent_status s
            INNER JOIN (
                SELECT session_id, MAX(created_at) AS last_seen
                FROM agent_status
                GROUP BY session_id
            ) m ON m.session_id = s.session_id
            WHERE m.last_seen >= :cutoff
            GROUP BY s.session_id
            HAVING SUM(CASE WHEN s.message LIKE 'RESULT:%' OR s.message LIKE 'ERROR:%' OR s.message LIKE 'DEPLOYMENT:%' THEN 1 ELSE 0 END) = 0
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('cutoff', $cutoff->format('Y-m-d H:i:s'));
        $res = $stmt->executeQuery();

        return array_map('strval', $res->fetchFirstColumn());
    }

    /**
     * Liefert den letzten Status (neuester Eintrag) für eine Session oder null
     */
    public function getLatestStatus(string $sessionId): ?array
    {
        // Check in-memory quick path
        if (!empty($this->inMemoryStatuses[$sessionId])) {
            $last = end($this->inMemoryStatuses[$sessionId]);
            return $last ?: null;
        }

        $repository = $this->em->getRepository(AgentStatus::class);
        $qb = $repository->createQueryBuilder('s');
        $qb->where('s.sessionId = :sessionId')
           ->setParameter('sessionId', $sessionId)
           ->orderBy('s.createdAt', 'DESC')
           ->setMaxResults(1);

        $status = $qb->getQuery()->getOneOrNullResult();

        if (!$status) {
            return null;
        }

        return [
            'timestamp' => $status->getCreatedAt()->format('c'),
            'message' => $status->getMessage(),
        ];
    }

    /**
     * Markiert eine Session als terminal (z.B. RESULT:, ERROR:, DEPLOYMENT:)
     */
    public function markTerminal(string $sessionId, string $marker): void
    {
        $this->addStatus($sessionId, $marker);
    }
}
