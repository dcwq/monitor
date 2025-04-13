<?php

namespace App\Repository;

use App\Entity\MonitorGroup;

interface MonitorGroupRepositoryInterface
{
    public function findAll(): array;

    public function findById(int $id): ?MonitorGroup;

    public function findByName(string $name): ?MonitorGroup;

    public function save(MonitorGroup $group): void;

    public function remove(MonitorGroup $group): void;
}