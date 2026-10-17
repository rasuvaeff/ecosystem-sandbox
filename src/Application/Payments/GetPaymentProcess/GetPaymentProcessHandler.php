<?php

declare(strict_types=1);

namespace App\Application\Payments\GetPaymentProcess;

use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessRepository;

final readonly class GetPaymentProcessHandler
{
    public function __construct(private PaymentProcessRepository $processes) {}

    /**
     * @return PaymentProcessView|null null until the worker takes the payment
     */
    public function handle(GetPaymentProcessQuery $query): ?PaymentProcessView
    {
        $process = $this->processes->find(orderId: $query->orderId);

        return $process instanceof PaymentProcess ? PaymentProcessView::of(process: $process) : null;
    }
}
