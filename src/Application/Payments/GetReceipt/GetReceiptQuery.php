<?php

declare(strict_types=1);

namespace App\Application\Payments\GetReceipt;

use App\Domain\Ordering\OrderId;

final readonly class GetReceiptQuery
{
    public function __construct(public OrderId $orderId) {}
}
