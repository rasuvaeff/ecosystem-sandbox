<?php

declare(strict_types=1);

namespace App\Domain\Payments\Event;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Event\DomainEvent;

final readonly class PaymentCaptured implements DomainEvent
{
    /**
     * @param non-empty-string $attemptKey identifies the attempt, so a redelivered outcome changes nothing twice
     */
    public function __construct(
        public OrderId $orderId,
        public string $attemptKey,
    ) {}
}
