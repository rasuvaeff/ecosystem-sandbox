<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderTimeline;

use App\Domain\Ordering\OrderId;

final readonly class GetOrderTimelineQuery
{
    public function __construct(public OrderId $orderId) {}
}
