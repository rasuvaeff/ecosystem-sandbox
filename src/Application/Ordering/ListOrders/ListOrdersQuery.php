<?php

declare(strict_types=1);

namespace App\Application\Ordering\ListOrders;

use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\Policy\OrderState;

final readonly class ListOrdersQuery
{
    /**
     * @param 'amount'|'recent' $sort
     * @param int<1, 100> $limit
     */
    public function __construct(
        public ?OrderStatus $status = null,
        public ?int $minAmountCents = null,
        public ?string $customerId = null,
        public string $sort = 'amount',
        public int $limit = 20,
        public ?OrderState $state = null,
    ) {}
}
