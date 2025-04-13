<?php
// bin/tasks.php
#!/usr/bin/env php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\NotificationService;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$action = $argv[1] ?? null;

switch ($action) {
    case 'check-overdue':
        $service = new NotificationService();
        $notificationCount = $service->checkOverdueMonitors();
        echo "Checked for overdue monitors. Sent $notificationCount notifications.\n";
        break;

    default:
        echo "Usage: php tasks.php [action]\n";
        echo "Available actions:\n";
        echo "  check-overdue - Check for overdue monitors and send notifications\n";
        exit(1);
}
