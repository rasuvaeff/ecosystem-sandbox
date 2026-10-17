<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\GetOrder\GetOrderHandler;
use App\Application\Ordering\GetOrder\GetOrderQuery;
use App\Application\Ordering\GetOrder\OrderView;
use App\Application\Ordering\GetOrderTimeline\GetOrderTimelineHandler;
use App\Application\Ordering\GetOrderTimeline\GetOrderTimelineQuery;
use App\Application\Ordering\ListOrders\ListOrdersHandler;
use App\Application\Ordering\ListOrders\ListOrdersQuery;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\TransitionRecord;

/**
 * What Ordering lets the other contexts ask: reads only, in the values
 * Ordering publishes (OrderView, TransitionRecord), never the aggregate. A
 * context that needs an order goes through here; deptrac-contexts.yaml
 * refuses any other way in. Ordering's own endpoints call the handlers.
 */
final readonly class OrderingApi
{
    public function __construct(
        private GetOrderHandler $order,
        private ListOrdersHandler $list,
        private GetOrderTimelineHandler $timeline,
    ) {}

    /**
     * @throws OrderNotFoundException
     */
    public function order(OrderId $orderId): OrderView
    {
        return $this->order->handle(query: new GetOrderQuery(orderId: $orderId));
    }

    /**
     * @return list<OrderView>
     */
    public function orders(ListOrdersQuery $query): array
    {
        return $this->list->handle(query: $query);
    }

    /**
     * @throws OrderNotFoundException
     *
     * @return list<TransitionRecord>
     */
    public function timeline(OrderId $orderId): array
    {
        return $this->timeline->handle(query: new GetOrderTimelineQuery(orderId: $orderId));
    }
}
