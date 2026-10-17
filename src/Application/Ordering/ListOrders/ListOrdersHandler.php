<?php

declare(strict_types=1);

namespace App\Application\Ordering\ListOrders;

use App\Application\Ordering\GetOrder\OrderView;
use App\Domain\Ordering\Specification\OrderSpecifications;

final readonly class ListOrdersHandler
{
    public function __construct(private OrderListReader $reader) {}

    /**
     * @return list<OrderView>
     */
    public function handle(ListOrdersQuery $query): array
    {
        return $this->reader->matching(specification: OrderSpecifications::listing(
            status: $query->status,
            minAmountCents: $query->minAmountCents,
            customerId: $query->customerId,
            sort: $query->sort,
            limit: $query->limit,
            state: $query->state,
        ));
    }
}
