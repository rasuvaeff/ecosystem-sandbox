<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'UTC'));
    }
}
