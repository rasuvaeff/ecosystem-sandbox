<?php

declare(strict_types=1);

namespace App\Domain\Payments\Event;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * The gateway declined, or the payment was given up: the request was
 * rejected, or the last attempt found no gateway.
 */
final readonly class PaymentDeclined implements DomainEvent
{
    /**
     * @param non-empty-string $attemptKey identifies the attempt, so a redelivered outcome changes nothing twice
     */
    public function __construct(
        public OrderId $orderId,
        public string $attemptKey,
    ) {}
}
