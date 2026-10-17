<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderEvidence;

use App\Domain\Ordering\OrderId;

final readonly class GetOrderEvidenceQuery
{
    public function __construct(public OrderId $orderId) {}
}
