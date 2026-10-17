<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * What an order costs, in US cents. The shop sells in one currency, so the
 * currency is part of the type rather than a field that could disagree.
 */
final readonly class Amount
{
    public const int MIN_CENTS = 1;
    public const int MAX_CENTS = 100_000_000;
    public const string CURRENCY = 'USD';

    /** @var int<1, 100000000> */
    public int $cents;

    public function __construct(int $cents)
    {
        if ($cents < self::MIN_CENTS || $cents > self::MAX_CENTS) {
            throw new \InvalidArgumentException(message: 'An amount is ' . self::MIN_CENTS . ' to ' . self::MAX_CENTS . ' cents');
        }
        $this->cents = $cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }
}
