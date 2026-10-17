<?php

declare(strict_types=1);

namespace App\Application\Marketing\GetAttribution;

use App\Domain\Ordering\OrderId;

final readonly class GetAttributionQuery
{
    public function __construct(public OrderId $orderId) {}
}
