<?php

declare(strict_types=1);

namespace App\Application\Payments\GetPaymentProcess;

use App\Domain\Ordering\OrderId;

final readonly class GetPaymentProcessQuery
{
    public function __construct(public OrderId $orderId) {}
}
