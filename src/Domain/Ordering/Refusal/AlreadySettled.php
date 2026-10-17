<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Refusal;

use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Shared\Refusal\Refusal;

/**
 * The order is paid or failed: a checkout cannot honestly accept it again.
 */
final readonly class AlreadySettled implements Refusal
{
    public function __construct(
        public OrderId $orderId,
        public OrderStatus $status,
    ) {}

    public function reason(): string
    {
        return sprintf('The order has already reached the status "%s"', $this->status->value);
    }
}
