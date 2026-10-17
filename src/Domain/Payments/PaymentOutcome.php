<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * What the worker concluded about one payment attempt, as the business
 * counter `sandbox_payment_outcomes_total{outcome}` counts it.
 */
enum PaymentOutcome: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Abandoned = 'abandoned';

    /** A charge found after the payment was given up: the order is paid after all. */
    case Recovered = 'recovered';
}
