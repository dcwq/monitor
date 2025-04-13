<?php

namespace App\Repository;

use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineMonitorRepository extends ServiceEntityRepository implements MonitorRepositoryInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Monitor::class);
        $this->entityManager = $registry->getManager();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.project_name', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id): ?Monitor
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?Monitor
    {
        return $this->createQueryBuilder('m')
            ->where('m.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByProjectName(string $projectName): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.project_name = :project_name')
            ->setParameter('project_name', $projectName)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getAllProjectNames(): array
    {
        return $this->createQueryBuilder('m')
            ->select('DISTINCT m.project_name')
            ->where('m.project_name IS NOT NULL')
            ->orderBy('m.project_name', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function save(Monitor $monitor): void
    {
        $this->entityManager->persist($monitor);
        $this->entityManager->flush();
    }

    public function remove(Monitor $monitor): void
    {
        $this->entityManager->remove($monitor);
        $this->entityManager->flush();
    }
}