<?php

declare(strict_types=1);

namespace App\Application\Ordering\Integration;

use App\Domain\Ordering\OrderId;

/**
 * The realtime channel of one order: the projection publishes there, and the
 * browser's token opens it and nothing else.
 */
final readonly class OrderChannel
{
    public static function of(string $orderId): string
    {
        return 'orders:' . $orderId;
    }

    public static function forOrder(OrderId $orderId): string
    {
        return self::of(orderId: $orderId->value);
    }
}
