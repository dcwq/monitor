<?php

namespace App\Repository;

use App\Entity\NotificationHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineNotificationHistoryRepository extends ServiceEntityRepository implements NotificationHistoryRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationHistory::class);
        $this->entityManager = $registry->getManager();
    }

    public function findByMonitorId(int $monitorId, int $limit = 10): array
    {
        return $this->createQueryBuilder('nh')
            ->join('nh.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitorId)
            ->orderBy('nh.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByChannelId(int $channelId, int $limit = 10): array
    {
        return $this->createQueryBuilder('nh')
            ->join('nh.channel', 'c')
            ->where('c.id = :channel_id')
            ->setParameter('channel_id', $channelId)
            ->orderBy('nh.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByEventType(string $eventType, int $limit = 10): array
    {
        return $this->createQueryBuilder('nh')
            ->where('nh.event_type = :event_type')
            ->setParameter('event_type', $eventType)
            ->orderBy('nh.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function save(NotificationHistory $history): void
    {
        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }

    public function remove(NotificationHistory $history): void
    {
        $this->entityManager->remove($history);
        $this->entityManager->flush();
    }
}