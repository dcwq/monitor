<?php

namespace App\Repository;

use App\Entity\Monitor;
use App\Entity\MonitorOverdueHistory;

interface MonitorOverdueHistoryRepositoryInterface
{
    public function findByMonitor(Monitor $monitor, int $limit = 10): array;

    public function findByMonitorId(int $monitorId, int $limit = 10): array;

    public function findUnresolvedByMonitor(Monitor $monitor): ?MonitorOverdueHistory;

    public function findUnresolvedByMonitorId(int $monitorId): ?MonitorOverdueHistory;

    public function save(MonitorOverdueHistory $history): void;

    public function remove(MonitorOverdueHistory $history): void;
}