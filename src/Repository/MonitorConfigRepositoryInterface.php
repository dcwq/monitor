<?php

namespace App\Repository;

use App\Entity\Monitor;
use App\Entity\MonitorConfig;

interface MonitorConfigRepositoryInterface
{
    public function findByMonitor(Monitor $monitor): ?MonitorConfig;

    public function findByMonitorId(int $monitorId): ?MonitorConfig;

    public function getOrCreate(Monitor $monitor): MonitorConfig;

    public function save(MonitorConfig $config): void;

    public function remove(MonitorConfig $config): void;
}