<?php

namespace App\Repository;

use App\Entity\GroupNotification;

interface GroupNotificationRepositoryInterface
{
    public function findByGroupId(int $groupId): array;

    public function findByChannelId(int $channelId): array;

    public function save(GroupNotification $notification): void;

    public function remove(GroupNotification $notification): void;

    public function removeAllForGroup(int $groupId): void;
}