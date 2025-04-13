<?php

namespace App\Repository;

use App\Entity\MonitorNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineMonitorNotificationRepository extends ServiceEntityRepository implements MonitorNotificationRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitorNotification::class);
        $this->entityManager = $registry->getManager();
    }

    public function findByMonitorId(int $monitorId): array
    {
        return $this->createQueryBuilder('mn')
            ->join('mn.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitorId)
            ->getQuery()
            ->getResult();
    }

    public function findByChannelId(int $channelId): array
    {
        return $this->createQueryBuilder('mn')
            ->join('mn.channel', 'c')
            ->where('c.id = :channel_id')
            ->setParameter('channel_id', $channelId)
            ->getQuery()
            ->getResult();
    }

    public function save(MonitorNotification $notification): void
    {
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    public function remove(MonitorNotification $notification): void
    {
        $this->entityManager->remove($notification);
        $this->entityManager->flush();
    }
}