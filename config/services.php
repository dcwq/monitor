<?php
// config/services.php

use App\Repository\DoctrineMonitorConfigRepository;
use App\Repository\DoctrineMonitorOverdueHistoryRepository;
use App\Repository\DoctrineMonitorRepository;
use App\Repository\DoctrinePingRepository;
use App\Repository\DoctrineTagRepository;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorOverdueHistoryRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Repository\TagRepositoryInterface;
use App\Services\ApiLogParser;
use App\Services\LogParser;
use App\Services\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

// Get the EntityManager
$entityManager = require_once __DIR__ . '/doctrine-bootstrap.php';

// Create ManagerRegistry for Doctrine repositories
$managerRegistry = new \App\Persistence\SimpleManagerRegistry($entityManager);

// Prepare service definitions
$serviceDefinitions = [];

// Simple service container
$container = new class($serviceDefinitions) {
    private array $definitions;
    private array $instances = [];

    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public function set(string $id, callable $factory): void
    {
        $this->definitions[$id] = $factory;
    }

    public function get(string $id)
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->definitions[$id])) {
                throw new \InvalidArgumentException("Service $id not found");
            }
            $this->instances[$id] = ($this->definitions[$id])();
        }

        return $this->instances[$id];
    }
};

// Rejestracja serwisów
$container->set(MonitorRepositoryInterface::class, fn() => new DoctrineMonitorRepository($managerRegistry));
$container->set(PingRepositoryInterface::class, fn() => new DoctrinePingRepository($managerRegistry));
$container->set(TagRepositoryInterface::class, fn() => new DoctrineTagRepository($managerRegistry));
$container->set(MonitorConfigRepositoryInterface::class, fn() => new DoctrineMonitorConfigRepository($managerRegistry));
$container->set(MonitorOverdueHistoryRepositoryInterface::class, fn() => new DoctrineMonitorOverdueHistoryRepository($managerRegistry));
    $container->set(EntityManagerInterface::class, fn() => $entityManager);

$container->set(NotificationService::class, function () use ($container, $entityManager) {
    return new NotificationService(
        $entityManager->getConnection(),
        $container->get(MonitorRepositoryInterface::class),
        $container->get(MonitorConfigRepositoryInterface::class),
        $container->get(MonitorOverdueHistoryRepositoryInterface::class)
    );
});

$container->set(LogParser::class, function () use ($container) {
    return new LogParser(
        $container->get(MonitorRepositoryInterface::class),
        $container->get(PingRepositoryInterface::class),
        $container->get(TagRepositoryInterface::class)
    );
});

$container->set(ApiLogParser::class, function () use ($container) {
    return new ApiLogParser(
        $container->get(MonitorRepositoryInterface::class),
        $container->get(PingRepositoryInterface::class),
        $container->get(TagRepositoryInterface::class)
    );
});

return $container;
