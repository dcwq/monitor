<?php

namespace App\Repository;

use App\Entity\Monitor;

interface MonitorRepositoryInterface
{
    public function findAll(): array;

    public function findById(int $id): ?Monitor;

    public function findByName(string $name): ?Monitor;

    public function findByProjectName(string $projectName): array;

    public function getAllProjectNames(): array;

    public function save(Monitor $monitor): void;

    public function remove(Monitor $monitor): void;
}