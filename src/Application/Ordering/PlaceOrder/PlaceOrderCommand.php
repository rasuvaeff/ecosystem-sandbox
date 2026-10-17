<?php

declare(strict_types=1);

namespace App\Application\Ordering\PlaceOrder;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;

final readonly class PlaceOrderCommand
{
    /**
     * @param non-empty-string $idempotencyKey the transition's key: the client's Idempotency-Key, or one derived from the order id
     */
    public function __construct(
        public OrderId $orderId,
        public CustomerId $customerId,
        public Amount $amount,
        public string $idempotencyKey,
    ) {}
}
