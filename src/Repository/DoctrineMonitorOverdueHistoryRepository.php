<?php

namespace App\Repository;

use App\Entity\Monitor;
use App\Entity\MonitorOverdueHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineMonitorOverdueHistoryRepository extends ServiceEntityRepository implements MonitorOverdueHistoryRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitorOverdueHistory::class);
        $this->entityManager = $registry->getManager();
    }

    public function findByMonitor(Monitor $monitor, int $limit = 10): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitor->getId())
            ->orderBy('h.started_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByMonitorId(int $monitorId, int $limit = 10): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitorId)
            ->orderBy('h.started_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findUnresolvedByMonitor(Monitor $monitor): ?MonitorOverdueHistory
    {
        return $this->createQueryBuilder('h')
            ->join('h.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->andWhere('h.is_resolved = :is_resolved')
            ->setParameter('monitor_id', $monitor->getId())
            ->setParameter('is_resolved', false)
            ->orderBy('h.started_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUnresolvedByMonitorId(int $monitorId): ?MonitorOverdueHistory
    {
        return $this->createQueryBuilder('h')
            ->join('h.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->andWhere('h.is_resolved = :is_resolved')
            ->setParameter('monitor_id', $monitorId)
            ->setParameter('is_resolved', false)
            ->orderBy('h.started_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(MonitorOverdueHistory $history): void
    {
        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }

    public function remove(MonitorOverdueHistory $history): void
    {
        $this->entityManager->remove($history);
        $this->entityManager->flush();
    }
}