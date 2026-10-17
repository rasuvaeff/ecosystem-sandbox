<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Refusal;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Refusal\Refusal;

/**
 * An order id names one purchase: a replay with another customer or amount
 * would charge for a request the client did not send.
 */
final readonly class PayloadConflict implements Refusal
{
    public function __construct(public OrderId $orderId) {}

    public function reason(): string
    {
        return 'The order already exists with a different customer or amount';
    }
}
