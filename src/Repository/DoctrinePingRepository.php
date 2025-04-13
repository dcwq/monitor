<?php

namespace App\Repository;

use App\Entity\Ping;
use App\Enum\PingState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrinePingRepository extends ServiceEntityRepository implements PingRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ping::class);
        $this->entityManager = $registry->getManager();
    }

    public function findByMonitorAndUniqueId(int $monitorId, string $uniqueId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->andWhere('p.unique_id = :unique_id')
            ->setParameter('monitor_id', $monitorId)
            ->setParameter('unique_id', $uniqueId)
            ->orderBy('p.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findRecentByMonitor(int $monitorId, int $limit = 10, ?string $state = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitorId);

        if ($state !== null) {
            $qb->andWhere('p.state = :state')
                ->setParameter('state', $state);
        }

        return $qb->orderBy('p.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getMonitorStats(int $monitorId, int $days = 7): array
    {
        $startTime = time() - ($days * 86400);

        // Get basic stats
        $conn = $this->entityManager->getConnection();
        $sql = '
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN state = :complete THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN state = :fail THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN state = :run THEN 1 ELSE 0 END) as running,
                AVG(CASE WHEN state = :complete AND duration IS NOT NULL THEN duration ELSE NULL END) as avg_duration,
                MAX(CASE WHEN state = :complete AND duration IS NOT NULL THEN duration ELSE NULL END) as max_duration,
                MIN(CASE WHEN state = :complete AND duration IS NOT NULL THEN duration ELSE NULL END) as min_duration
            FROM pings
            WHERE monitor_id = :monitor_id
              AND timestamp >= :start_time
              AND state IN (:complete, :fail, :run)
        ';
        $stmt = $conn->prepare($sql);
        $resultSet = $stmt->executeQuery([
            'monitor_id' => $monitorId,
            'start_time' => $startTime,
            'complete' => PingState::COMPLETE->value,
            'fail' => PingState::FAIL->value,
            'run' => PingState::RUN->value
        ]);
        $stats = $resultSet->fetchAssociative();

        // Get time-based data
        $timeSql = '
            SELECT 
                DATE_FORMAT(FROM_UNIXTIME(timestamp), "%Y-%m-%d %H:00:00") as hour,
                AVG(duration) as avg_duration,
                COUNT(*) as count
            FROM pings
            WHERE monitor_id = :monitor_id
              AND timestamp >= :start_time
              AND state = :complete
            GROUP BY hour
            ORDER BY hour
        ';
        $timeStmt = $conn->prepare($timeSql);
        $timeResultSet = $timeStmt->executeQuery([
            'monitor_id' => $monitorId,
            'start_time' => $startTime,
            'complete' => PingState::COMPLETE->value
        ]);
        $timeData = $timeResultSet->fetchAllAssociative();

        $stats['time_data'] = $timeData;

        // Calculate success rate
        try {
            $stats['success_rate'] = $stats['total'] > 0
                ? ($stats['completed'] / ($stats['completed'] + $stats['failed'])) * 100
                : 0;
        } catch (\Throwable $e) {
            $stats['success_rate'] = 0;
        }

        return $stats;
    }

    public function save(Ping $ping): void
    {
        $this->entityManager->persist($ping);
        $this->entityManager->flush();
    }

    public function remove(Ping $ping): void
    {
        $this->entityManager->remove($ping);
        $this->entityManager->flush();
    }

    public function countByMonitor(int $monitorId, ?string $state = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.monitor = :monitor_id')
            ->setParameter('monitor_id', $monitorId);

        if ($state !== null) {
            $qb->andWhere('p.state = :state')
                ->setParameter('state', $state);
        }

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function findRecentByMonitorWithPagination(int $monitorId, int $limit = 10, int $offset = 0, ?string $state = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitorId);

        if ($state !== null) {
            $qb->andWhere('p.state = :state')
                ->setParameter('state', $state);
        }

        return $qb->orderBy('p.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}