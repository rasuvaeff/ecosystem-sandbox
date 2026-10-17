<?php

declare(strict_types=1);

namespace App\Domain\Payments\Event;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * The reconciliation found the money taken for a payment that had been given
 * up: the order the shop failed is paid after all.
 */
final readonly class PaymentRecovered implements DomainEvent
{
    /**
     * @param non-empty-string $recoveryKey identifies the recovery, so a redelivered reconciliation moves the order once
     */
    public function __construct(
        public OrderId $orderId,
        public string $recoveryKey,
    ) {}
}
