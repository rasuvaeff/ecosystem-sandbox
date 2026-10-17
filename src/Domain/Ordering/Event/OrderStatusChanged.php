<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Event;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Shared\Event\DomainEvent;

final readonly class OrderStatusChanged implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public Amount $amount,
        public OrderTransition $transition,
        public OrderStatus $from,
        public OrderStatus $to,
    ) {}
}
