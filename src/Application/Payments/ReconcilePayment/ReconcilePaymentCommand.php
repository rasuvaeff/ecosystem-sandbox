<?php

declare(strict_types=1);

namespace App\Application\Payments\ReconcilePayment;

use App\Domain\Ordering\OrderId;

final readonly class ReconcilePaymentCommand
{
    public function __construct(public OrderId $orderId) {}
}
