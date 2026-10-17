<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;

/**
 * One request to take an order's money. The idempotency key travels to the
 * gateway, so repeating the request never charges twice.
 */
final readonly class Charge
{
    /**
     * @param non-empty-string $idempotencyKey
     */
    public function __construct(
        public OrderId $orderId,
        public Amount $amount,
        public string $idempotencyKey,
    ) {}
}
