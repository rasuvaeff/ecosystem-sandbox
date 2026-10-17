<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Metrics;

use App\Domain\Payments\PaymentOutcome;
use App\Domain\Payments\PaymentOutcomes;
use Rasuvaeff\Yii3Metrics\CounterDefinition;
use Rasuvaeff\Yii3Metrics\MetricRegistry;

final readonly class MetricPaymentOutcomes implements PaymentOutcomes
{
    public function __construct(private MetricRegistry $metrics) {}

    public function record(PaymentOutcome $outcome): void
    {
        $this->metrics->counter(new CounterDefinition(
            name: 'sandbox_payment_outcomes_total',
            help: 'Payment worker business outcomes',
            labelNames: ['outcome'],
        ))->inc(labels: ['outcome' => $outcome->value]);
    }
}
