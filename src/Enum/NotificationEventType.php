<?php

namespace App\Enum;

enum NotificationEventType: string
{
    case FAIL = 'fail';
    case OVERDUE = 'overdue';
    case RESOLVE = 'resolve';

    public static function fromString(string $type): self
    {
        return match ($type) {
            'fail' => self::FAIL,
            'overdue' => self::OVERDUE,
            'resolve' => self::RESOLVE,
            default => throw new \InvalidArgumentException("Unknown notification event type: $type")
        };
    }
}