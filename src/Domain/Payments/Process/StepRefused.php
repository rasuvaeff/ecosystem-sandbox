<?php

declare(strict_types=1);

namespace App\Domain\Payments\Process;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Refusal\Refusal;

final readonly class StepRefused implements Refusal
{
    public function __construct(
        public OrderId $orderId,
        public PaymentProcessStep $step,
        public PaymentProcessStatus $status,
    ) {}

    public function reason(): string
    {
        return sprintf('Payment step "%s" is not allowed from "%s"', $this->step->value, $this->status->value);
    }
}
