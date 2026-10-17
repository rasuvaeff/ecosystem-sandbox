<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrder;

use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderRepository;

final readonly class GetOrderHandler
{
    public function __construct(private OrderRepository $orders) {}

    /**
     * @throws OrderNotFoundException
     */
    public function handle(GetOrderQuery $query): OrderView
    {
        $order = $this->orders->find(id: $query->orderId) ?? throw new OrderNotFoundException(orderId: $query->orderId);

        return OrderView::of(order: $order);
    }
}
