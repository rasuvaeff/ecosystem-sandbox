<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

final readonly class CustomerId
{
    public const int MAX_LENGTH = 64;

    /** @var non-empty-string */
    public string $value;

    public function __construct(string $value)
    {
        if ($value === '' || mb_strlen(string: $value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(message: 'A customer id is 1 to ' . self::MAX_LENGTH . ' characters');
        }
        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
