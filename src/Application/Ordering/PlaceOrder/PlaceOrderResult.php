<?php

declare(strict_types=1);

namespace App\Application\Ordering\PlaceOrder;

use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;

final readonly class PlaceOrderResult
{
    /**
     * @param bool $accepted whether this request started the payment (false: it was already on its way)
     */
    public function __construct(
        public OrderId $orderId,
        public OrderStatus $status,
        public bool $accepted,
    ) {}
}
