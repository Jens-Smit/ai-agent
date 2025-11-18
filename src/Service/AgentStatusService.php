<?php
namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
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
        // Behält das flush() bei, da in einem Status-Service eine sofortige Persistierung oft beabsichtigt ist
        // (z.B. für Monitoring/Polling), aber beachte, dass dies bei sehr vielen Aufrufen performanter sein könnte,
        // wenn der Aufrufer das flush() außerhalb des Service übernimmt.
        $this->em->flush();

        // Read-through In-Memory cache (wird nur pro Request gepflegt)
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
     *
     * HINWEIS: Performance-Verbesserung durch DQL DELETE statt Entity-Loop.
     */
    public function clearStatuses(string $sessionId): void
    {
        // DQL DELETE für bessere Performance bei vielen Datensätzen
        $qb = $this->em->createQueryBuilder();
        $qb->delete(AgentStatus::class, 's')
           ->where('s.sessionId = :sessionId')
           ->setParameter('sessionId', $sessionId)
           ->getQuery()
           ->execute();

        // Clear in-memory cache
        unset($this->inMemoryStatuses[$sessionId]);
    }

    /**
     * Liefert die IDs aller Sessions, sortiert nach dem Zeitpunkt ihres letzten Status-Eintrags.
     */
    public function getAllSessionIds(int $limit = 1000): array
    {
        $conn = $this->em->getConnection();
        // FIX: Hinzufügen von GROUP BY, um nach dem aktuellsten created_at pro session_id zu sortieren.
        $sql = 'SELECT session_id FROM agent_status GROUP BY session_id ORDER BY MAX(created_at) DESC LIMIT :limit';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $res = $stmt->executeQuery();

        return array_map('strval', $res->fetchFirstColumn());
    }

    /**
     * Liefert aktive Session IDs.
     * "Aktiv" bedeutet: letzter Eintrag neuer als $activeWithin und kein terminaler Marker (RESULT:, ERROR:, DEPLOYMENT:)
     */
    public function getActiveSessionIds(\DateInterval $activeWithin = null): array
    {
        $activeWithin = $activeWithin ?? new \DateInterval('PT15M'); // default 15 Minuten
        $cutoff = (new \DateTimeImmutable())->sub($activeWithin);

        // Query: Sessions mit last_seen > cutoff und ohne terminalen Marker
        $conn = $this->em->getConnection();
        // Der Query prüft Sessions, deren letzter Status neuer als :cutoff ist UND die keinen terminalen Marker enthalten.
        $sql = "
            SELECT T1.session_id
            FROM agent_status AS T1
            INNER JOIN (
                -- Subquery, um die letzte Aktivität jeder Session zu finden
                SELECT session_id, MAX(created_at) AS last_seen
                FROM agent_status
                GROUP BY session_id
                HAVING MAX(created_at) >= :cutoff
            ) AS T2 ON T1.session_id = T2.session_id
            GROUP BY T1.session_id
            -- HAVING-Filter, um Sessions auszuschließen, die jemals einen terminalen Marker hatten
            HAVING SUM(CASE WHEN T1.message LIKE 'RESULT:%' OR T1.message LIKE 'ERROR:%' OR T1.message LIKE 'DEPLOYMENT:%' THEN 1 ELSE 0 END) = 0
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
            // end() gibt das letzte Element zurück, ohne den Zeiger zu verschieben (da es als Wert zurückgegeben wird)
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