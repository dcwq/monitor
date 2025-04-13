<?php

namespace App\Repository;

use App\Entity\GroupNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineGroupNotificationRepository extends ServiceEntityRepository implements GroupNotificationRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupNotification::class);
        $this->entityManager = $registry->getManager();
    }

    public function findByGroupId(int $groupId): array
    {
        return $this->createQueryBuilder('gn')
            ->join('gn.group', 'g')
            ->where('g.id = :group_id')
            ->setParameter('group_id', $groupId)
            ->getQuery()
            ->getResult();
    }

    public function findByChannelId(int $channelId): array
    {
        return $this->createQueryBuilder('gn')
            ->join('gn.channel', 'c')
            ->where('c.id = :channel_id')
            ->setParameter('channel_id', $channelId)
            ->getQuery()
            ->getResult();
    }

    public function save(GroupNotification $notification): void
    {
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    public function remove(GroupNotification $notification): void
    {
        $this->entityManager->remove($notification);
        $this->entityManager->flush();
    }

    public function removeAllForGroup(int $groupId): void
    {
        $this->createQueryBuilder('gn')
            ->delete()
            ->where('gn.group = :group_id')
            ->setParameter('group_id', $groupId)
            ->getQuery()
            ->execute();
    }
}