<?php
// config/services.php

use App\Repository\DoctrineGroupNotificationRepository;
use App\Repository\DoctrineMonitorConfigRepository;
use App\Repository\DoctrineMonitorGroupRepository;
use App\Repository\DoctrineMonitorNotificationRepository;
use App\Repository\DoctrineMonitorOverdueHistoryRepository;
use App\Repository\DoctrineMonitorRepository;
use App\Repository\DoctrineNotificationChannelRepository;
use App\Repository\DoctrineNotificationHistoryRepository;
use App\Repository\DoctrinePingRepository;
use App\Repository\DoctrineTagRepository;
use App\Repository\GroupNotificationRepositoryInterface;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorGroupRepositoryInterface;
use App\Repository\MonitorNotificationRepositoryInterface;
use App\Repository\MonitorOverdueHistoryRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\NotificationChannelRepositoryInterface;
use App\Repository\NotificationHistoryRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Repository\TagRepositoryInterface;
use App\Services\ApiLogParser;
use App\Services\CronIntervalCalculator;
use App\Services\LogParser;
use App\Services\MonitorSchedulerService;
use App\Services\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

// Get the EntityManager
$entityManager = require_once __DIR__ . '/doctrine-bootstrap.php';

// Create ManagerRegistry for Doctrine repositories
$managerRegistry = new \App\Persistence\SimpleManagerRegistry($entityManager);

// Prepare service definitions
$serviceDefinitions = [];

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

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
$container->set(NotificationChannelRepositoryInterface::class, fn() => new DoctrineNotificationChannelRepository($managerRegistry));
$container->set(NotificationHistoryRepositoryInterface::class, fn() => new DoctrineNotificationHistoryRepository($managerRegistry));
$container->set(MonitorNotificationRepositoryInterface::class, fn() => new DoctrineMonitorNotificationRepository($managerRegistry));
$container->set(MonitorGroupRepositoryInterface::class, fn() => new DoctrineMonitorGroupRepository($managerRegistry));
$container->set(GroupNotificationRepositoryInterface::class, fn() => new DoctrineGroupNotificationRepository($managerRegistry));
$container->set(EntityManagerInterface::class, fn() => $entityManager);

// Rejestracja nowego serwisu MonitorSchedulerService
$container->set(MonitorSchedulerService::class, function () use ($container) {
    return new MonitorSchedulerService(
        $container->get(MonitorConfigRepositoryInterface::class),
        $container->get(PingRepositoryInterface::class)
    );
});

$container->set(NotificationService::class, function () use ($container) {
    return new NotificationService(
        $container->get(EntityManagerInterface::class),
        $container->get(MonitorRepositoryInterface::class),
        $container->get(MonitorConfigRepositoryInterface::class),
        $container->get(MonitorOverdueHistoryRepositoryInterface::class),
        $container->get(NotificationChannelRepositoryInterface::class),
        $container->get(GroupNotificationRepositoryInterface::class)
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