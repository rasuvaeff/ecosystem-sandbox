<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ListOrders\Data;

use App\Endpoint\Rest\Ordering\Shared\Data\OrderResult;

/**
 * The filtered order list.
 */
final readonly class OrderListResult implements \JsonSerializable
{
    /**
     * @param list<OrderResult> $items
     */
    public function __construct(public array $items) {}

    /**
     * @return array{items: list<OrderResult>, count: int}
     */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'count' => count(value: $this->items)];
    }
}
