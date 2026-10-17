<?php

declare(strict_types=1);

namespace App\Domain\Payments;

interface PaymentOutcomes
{
    public function record(PaymentOutcome $outcome): void;
}
