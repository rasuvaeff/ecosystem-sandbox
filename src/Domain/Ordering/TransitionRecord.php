<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

final readonly class TransitionRecord
{
    public function __construct(
        public string $transition,
        public string $from,
        public string $to,
        public \DateTimeImmutable $at,
    ) {}
}
