<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessRepository;

/**
 * Stores the process by value, as a database would: a caller holding an old
 * instance does not see another caller's save until it reads again.
 */
final class InMemoryPaymentProcesses implements PaymentProcessRepository
{
    /** @var array<string, PaymentProcess> */
    private array $processes = [];

    public function find(OrderId $orderId): ?PaymentProcess
    {
        $stored = $this->processes[$orderId->value] ?? null;

        return $stored === null ? null : clone $stored;
    }

    public function addIfAbsent(PaymentProcess $process): bool
    {
        if (isset($this->processes[$process->orderId()->value])) {
            return false;
        }
        $this->processes[$process->orderId()->value] = clone $process;

        return true;
    }

    public function save(PaymentProcess $process): void
    {
        $this->processes[$process->orderId()->value] = clone $process;
    }
}
