#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\LogParser;
use App\Services\ApiLogParser;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

echo "Starting log import...\n";

// Parse history log
$historyParser = new LogParser();
try {
    $historyImportCount = $historyParser->parse(false);
    echo "Successfully imported {$historyImportCount} history log entries.\n";
} catch (Exception $e) {
    echo "Error importing history log: " . $e->getMessage() . "\n";
}

// Parse API log
$apiParser = new ApiLogParser();
try {
    $apiImportCount = $apiParser->parse(false);
    echo "Successfully imported {$apiImportCount} API log entries.\n";
} catch (Exception $e) {
    echo "Error importing API log: " . $e->getMessage() . "\n";
}

$totalImportCount = ($historyImportCount ?? 0) + ($apiImportCount ?? 0);
echo "Total imported: {$totalImportCount} log entries.\n";
