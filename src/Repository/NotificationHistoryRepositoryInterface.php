<?php

namespace App\Repository;

use App\Entity\NotificationHistory;

interface NotificationHistoryRepositoryInterface
{
    public function findByMonitorId(int $monitorId, int $limit = 10): array;

    public function findByChannelId(int $channelId, int $limit = 10): array;

    public function findByEventType(string $eventType, int $limit = 10): array;

    public function save(NotificationHistory $history): void;

    public function remove(NotificationHistory $history): void;
}