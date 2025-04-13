<?php

namespace App\Repository;

use App\Entity\NotificationChannel;

interface NotificationChannelRepositoryInterface
{
    public function findAll(): array;

    public function findById(int $id): ?NotificationChannel;

    public function findByType(string $type): array;

    public function findChannelsForMonitor(int $monitorId): array;

    public function addMonitorNotification(int $monitorId, int $channelId, bool $notifyOnFail, bool $notifyOnOverdue, bool $notifyOnResolve): void;

    public function removeAllMonitorNotifications(int $monitorId): void;

    public function save(NotificationChannel $channel): void;

    public function remove(NotificationChannel $channel): void;
}