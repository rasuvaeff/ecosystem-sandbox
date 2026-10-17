<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderTimeline;

use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderRepository;
use App\Domain\Ordering\TransitionHistory;
use App\Domain\Ordering\TransitionRecord;

final readonly class GetOrderTimelineHandler
{
    public function __construct(
        private OrderRepository $orders,
        private TransitionHistory $history,
    ) {}

    /**
     * @throws OrderNotFoundException
     *
     * @return list<TransitionRecord>
     */
    public function handle(GetOrderTimelineQuery $query): array
    {
        if (!$this->orders->find(id: $query->orderId) instanceof Order) {
            throw new OrderNotFoundException(orderId: $query->orderId);
        }

        return $this->history->forOrder(orderId: $query->orderId);
    }
}
