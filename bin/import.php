#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\ApiLogParser;
use App\Services\LogParser;

// Load EntityManager
$entityManager = require_once __DIR__ . '/../config/doctrine-bootstrap.php';

// Create ManagerRegistry for Doctrine repositories
$managerRegistry = new \App\Persistence\SimpleManagerRegistry($entityManager);

$monitorRepository = new \App\Repository\DoctrineMonitorRepository($managerRegistry);
$pingRepository = new \App\Repository\DoctrinePingRepository($managerRegistry);
$tagRepository = new \App\Repository\DoctrineTagRepository($managerRegistry);

echo "Starting log import...\n";

// Parse history log
$historyParser = new LogParser($monitorRepository, $pingRepository, $tagRepository);
try {
    $historyImportCount = $historyParser->parse(true);
    echo "Successfully imported {$historyImportCount} history log entries.\n";
} catch (Exception $e) {
    echo "Error importing history log: " . $e->getMessage() . "\n";
}

// Parse API log
$apiParser = new ApiLogParser($monitorRepository, $pingRepository, $tagRepository);
try {
    $apiImportCount = $apiParser->parse(true);
    echo "Successfully imported {$apiImportCount} API log entries.\n";
} catch (Exception $e) {
    echo "Error importing API log: " . $e->getMessage() . "\n";
}

$totalImportCount = ($historyImportCount ?? 0) + ($apiImportCount ?? 0);
echo "Total imported: {$totalImportCount} log entries.\n";