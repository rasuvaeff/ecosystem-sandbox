<?php

declare(strict_types=1);

namespace App\Domain\Payments\Process;

use App\Domain\Ordering\OrderId;

interface PaymentProcessRepository
{
    public function find(OrderId $orderId): ?PaymentProcess;

    /**
     * @return bool false when the order's process already exists
     */
    public function addIfAbsent(PaymentProcess $process): bool;

    public function save(PaymentProcess $process): void;
}
