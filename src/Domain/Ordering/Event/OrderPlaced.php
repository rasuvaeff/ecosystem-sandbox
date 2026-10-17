<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Event;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Event\DomainEvent;

final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public CustomerId $customerId,
        public Amount $amount,
    ) {}
}
