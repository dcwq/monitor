<?php
// src/Notifications/SmsAdapter.php

namespace App\Notifications;

use Monolog\Level;
use Monolog\Logger;

readonly class LogAdapter implements NotificationAdapterInterface
{
    public function __construct(private Logger $logger)
    {
    }

    public function send(string $message, array $config): bool
    {
        $this->logger->pushHandler(new StreamHandler('./data/log-adapter', Level::Warning));
        $this->logger->info($message);

        return true;
    }
}