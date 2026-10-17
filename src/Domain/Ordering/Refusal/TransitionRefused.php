<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Refusal;

use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Shared\Refusal\Refusal;

/**
 * The transition does not start from the order's current status.
 */
final readonly class TransitionRefused implements Refusal
{
    public function __construct(
        public OrderId $orderId,
        public OrderTransition $transition,
        public OrderStatus $status,
    ) {}

    public function reason(): string
    {
        return sprintf('Transition "%s" is not allowed from "%s"', $this->transition->value, $this->status->value);
    }
}
