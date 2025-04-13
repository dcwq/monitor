<?php

namespace App\Repository;

use App\Entity\Tag;

interface TagRepositoryInterface
{
    public function findAll(): array;

    public function findByName(string $name): ?Tag;

    public function save(Tag $tag): void;

    public function remove(Tag $tag): void;
}