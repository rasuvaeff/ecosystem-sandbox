<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Ordering\GetOrder\GetOrderHandler;
use App\Application\Ordering\GetOrderTimeline\GetOrderTimelineHandler;
use App\Application\Ordering\ListOrders\ListOrdersHandler;
use App\Application\Ordering\ListOrders\OrderListReader;
use App\Application\Ordering\OrderingApi;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\TransitionHistory;
use Rasuvaeff\Understudy\Understudy;

/**
 * Ordering's facade over orders held in memory, for the other contexts'
 * tests: reads go through the real handlers, the list and the history are
 * doubles nothing in those tests asks.
 */
final class OrderingApis
{
    public static function over(Order ...$orders): OrderingApi
    {
        $repository = new InMemoryOrderRepository(...$orders);

        return new OrderingApi(
            order: new GetOrderHandler(orders: $repository),
            list: new ListOrdersHandler(reader: Understudy::for(OrderListReader::class)),
            timeline: new GetOrderTimelineHandler(orders: $repository, history: Understudy::for(TransitionHistory::class)),
        );
    }
}
