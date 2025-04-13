<?php

namespace App\Repository;

use App\Entity\Ping;
use App\Enum\PingState;

interface PingRepositoryInterface
{
    public function findByMonitorAndUniqueId(int $monitorId, string $uniqueId): array;

    public function findRecentByMonitor(int $monitorId, int $limit = 10, ?string $state = null): array;

    public function getMonitorStats(int $monitorId, int $days = 7): array;

    public function save(Ping $ping): void;

    public function remove(Ping $ping): void;
}