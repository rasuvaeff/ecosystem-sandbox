<?php

declare(strict_types=1);

namespace App\Application\Operations\GetCheckoutTrace;

use App\Domain\Ordering\OrderId;
use Rasuvaeff\Context\Context;

final readonly class GetCheckoutTraceQuery
{
    public function __construct(
        public OrderId $orderId,
        public Context $budget,
    ) {}
}
