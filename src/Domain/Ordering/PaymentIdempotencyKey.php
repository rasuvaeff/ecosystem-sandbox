<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * The idempotency key of the one gateway charge an order may cause. Stable
 * per order, so a charge that landed unseen is never repeated; hashed,
 * because an order id may hold any character.
 */
final readonly class PaymentIdempotencyKey
{
    /**
     * @return non-empty-string
     */
    public static function forOrder(OrderId $orderId): string
    {
        return 'payment-' . hash(algo: 'sha256', data: $orderId->value);
    }
}
