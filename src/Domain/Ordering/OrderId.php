<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * An order's id as the client chose it: any 1-64 characters (spaces and
 * non-ASCII included), so it never enters a path, a header or a key unhashed.
 */
final readonly class OrderId
{
    public const int MAX_LENGTH = 64;

    /** @var non-empty-string */
    public string $value;

    public function __construct(string $value)
    {
        if ($value === '' || mb_strlen(string: $value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(message: 'An order id is 1 to ' . self::MAX_LENGTH . ' characters');
        }
        $this->value = $value;
    }

    /**
     * An id from a URL or a tool argument: one no order can have is simply
     * not found, rather than a malformed request.
     */
    public static function tryFrom(string $value): ?self
    {
        return $value === '' || mb_strlen(string: $value) > self::MAX_LENGTH ? null : new self(value: $value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
