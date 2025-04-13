<?php

namespace App\Repository;

use App\Entity\MonitorNotification;

interface MonitorNotificationRepositoryInterface
{
    public function findByMonitorId(int $monitorId): array;

    public function findByChannelId(int $channelId): array;

    public function save(MonitorNotification $notification): void;

    public function remove(MonitorNotification $notification): void;
}