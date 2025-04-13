<?php
// bin/tasks.php
#!/usr/bin/env php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\NotificationService;

// Load EntityManager
$entityManager = require_once __DIR__ . '/../config/doctrine-bootstrap.php';
$managerRegistry = new \App\Persistence\SimpleManagerRegistry($entityManager);

// Load repositories and services via Dependency Injection
// Create ManagerRegistry for Doctrine repositories

$monitorRepository = new \App\Repository\DoctrineMonitorRepository($managerRegistry);
$monitorConfigRepository = new \App\Repository\DoctrineMonitorConfigRepository($managerRegistry);
$overdueHistoryRepository = new \App\Repository\DoctrineMonitorOverdueHistoryRepository($managerRegistry);

$notificationChannelRepository = new \App\Repository\DoctrineNotificationChannelRepository($managerRegistry);
$groupNotificationRepository = new \App\Repository\DoctrineGroupNotificationRepository($managerRegistry);

$notificationService = new NotificationService(
    $entityManager,
    $monitorRepository,
    $monitorConfigRepository,
    $overdueHistoryRepository,
    $notificationChannelRepository,
    $groupNotificationRepository
);

$action = $argv[1] ?? null;

switch ($action) {
    case 'check-overdue':
        $notificationCount = $notificationService->checkOverdueMonitors();
        echo "Checked for overdue monitors. Sent $notificationCount notifications.\n";
        break;

    default:
        echo "Usage: php tasks.php [action]\n";
        echo "Available actions:\n";
        echo "  check-overdue - Check for overdue monitors and send notifications\n";
        exit(1);
}