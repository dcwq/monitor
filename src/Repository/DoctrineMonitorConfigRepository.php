<?php

namespace App\Repository;

use App\Entity\Monitor;
use App\Entity\MonitorConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineMonitorConfigRepository extends ServiceEntityRepository implements MonitorConfigRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitorConfig::class);
        $this->entityManager = $registry->getManager();
    }

    public function findByMonitor(Monitor $monitor): ?MonitorConfig
    {
        return $this->createQueryBuilder('mc')
            ->join('mc.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitor->getId())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByMonitorId(int $monitorId): ?MonitorConfig
    {
        return $this->createQueryBuilder('mc')
            ->join('mc.monitor', 'm')
            ->where('m.id = :monitor_id')
            ->setParameter('monitor_id', $monitorId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getOrCreate(Monitor $monitor): MonitorConfig
    {
        $config = $this->findByMonitor($monitor);

        if ($config === null) {
            $config = new MonitorConfig($monitor);
            $this->save($config);
        }

        return $config;
    }

    public function save(MonitorConfig $config): void
    {
        $this->entityManager->persist($config);
        $this->entityManager->flush();
    }

    public function remove(MonitorConfig $config): void
    {
        $this->entityManager->remove($config);
        $this->entityManager->flush();
    }
}