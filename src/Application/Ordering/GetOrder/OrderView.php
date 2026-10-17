<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrder;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;

/**
 * An order as other contexts may see it: values, never the aggregate.
 */
final readonly class OrderView
{
    public function __construct(
        public OrderId $orderId,
        public CustomerId $customerId,
        public Amount $amount,
        public OrderStatus $status,
    ) {}

    public static function of(Order $order): self
    {
        return new self(orderId: $order->id(), customerId: $order->customerId(), amount: $order->amount(), status: $order->status());
    }
}
