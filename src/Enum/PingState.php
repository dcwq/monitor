<?php

namespace App\Enum;

enum PingState: string
{
    case COMPLETE = 'complete';
    case FAIL = 'fail';
    case RUN = 'run';

    public static function fromString(string $state): self
    {
        return match ($state) {
            'complete' => self::COMPLETE,
            'fail' => self::FAIL,
            'run' => self::RUN,
            default => throw new \InvalidArgumentException("Unknown ping state: $state")
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::COMPLETE;
    }

    public function isFailure(): bool
    {
        return $this === self::FAIL;
    }

    public function isRunning(): bool
    {
        return $this === self::RUN;
    }
}