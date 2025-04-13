<?php

namespace App\Repository;

use App\Entity\Monitor;
use App\Entity\MonitorNotification;
use App\Entity\NotificationChannel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineNotificationChannelRepository extends ServiceEntityRepository implements NotificationChannelRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationChannel::class);
        $this->entityManager = $registry->getManager();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('nc')
            ->orderBy('nc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id): ?NotificationChannel
    {
        return $this->find($id);
    }

    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('nc')
            ->where('nc.type = :type')
            ->setParameter('type', $type)
            ->orderBy('nc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findChannelsForMonitor(int $monitorId): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        $result = $qb->select('mn')
            ->from(MonitorNotification::class, 'mn')
            ->where('mn.monitor = :monitor_id')
            ->setParameter('monitor_id', $monitorId)
            ->getQuery()
            ->getResult();

        $channelsMap = [];
        foreach ($result as $notification) {
            $channelId = $notification->getChannel()->getId();
            $channelsMap[$channelId] = [
                'channel_id' => $channelId,
                'notify_on_fail' => $notification->isNotifyOnFail(),
                'notify_on_overdue' => $notification->isNotifyOnOverdue(),
                'notify_on_resolve' => $notification->isNotifyOnResolve()
            ];
        }

        return $channelsMap;
    }

    public function addMonitorNotification(int $monitorId, int $channelId, bool $notifyOnFail, bool $notifyOnOverdue, bool $notifyOnResolve): void
    {
        $monitor = $this->entityManager->getReference(Monitor::class, $monitorId);
        $channel = $this->entityManager->getReference(NotificationChannel::class, $channelId);

        $notification = new MonitorNotification();
        $notification->setMonitor($monitor);
        $notification->setChannel($channel);
        $notification->setNotifyOnFail($notifyOnFail);
        $notification->setNotifyOnOverdue($notifyOnOverdue);
        $notification->setNotifyOnResolve($notifyOnResolve);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    public function removeAllMonitorNotifications(int $monitorId): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->delete(MonitorNotification::class, 'mn')
            ->where('mn.monitor = :monitor_id')
            ->setParameter('monitor_id', $monitorId)
            ->getQuery()
            ->execute();
    }

    public function save(NotificationChannel $channel): void
    {
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
    }

    public function remove(NotificationChannel $channel): void
    {
        $this->entityManager->remove($channel);
        $this->entityManager->flush();
    }
}