<?php

namespace App\Repository;

use App\Entity\MonitorGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineMonitorGroupRepository extends ServiceEntityRepository implements MonitorGroupRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitorGroup::class);
        $this->entityManager = $registry->getManager();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id): ?MonitorGroup
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?MonitorGroup
    {
        return $this->createQueryBuilder('g')
            ->where('g.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(MonitorGroup $group): void
    {
        $this->entityManager->persist($group);
        $this->entityManager->flush();
    }

    public function remove(MonitorGroup $group): void
    {
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }
}