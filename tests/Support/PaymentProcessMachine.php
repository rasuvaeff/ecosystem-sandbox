<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessStatus;
use App\Domain\Payments\Process\PaymentProcessStep;
use App\Domain\Payments\Process\StepRefused;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;
use Testo\Assert;

/**
 * The payment saga under any order of events, duplicates included: each
 * step applies once from its own status and is refused as a value from any
 * other, and a recovery happens at most once, only after the payment was
 * abandoned.
 */
final class PaymentProcessMachine
{
    private PaymentProcessStatus $model = PaymentProcessStatus::Requested;

    private int $recoveries = 0;

    private readonly PaymentProcess $process;

    public function __construct()
    {
        $this->process = PaymentProcess::start(orderId: new OrderId(value: 'order-1'), amount: new Amount(cents: 1999), gatewayKey: 'payment-order-1');
    }

    #[Rule]
    public function deliver(PaymentProcessStep $step): void
    {
        $allowed = $step->source() === $this->model;
        $result = $this->process->take(step: $step);

        Assert::same($result->isOk(), $allowed);
        if ($result->isErr()) {
            $refused = $result->error();
            Assert::instanceOf($refused, StepRefused::class);

            return;
        }
        Assert::same($result->value(), $step->target());
        $this->model = $step->target();
        if ($step === PaymentProcessStep::ConfirmCharged) {
            ++$this->recoveries;
        }
    }

    #[Invariant]
    public function statusFollowsTheModel(): void
    {
        Assert::same($this->process->status(), $this->model);
    }

    #[Invariant]
    public function aRecoveryHappensAtMostOnceAndOnlyFromAnAbandonedPayment(): void
    {
        Assert::true($this->recoveries <= 1);
        Assert::same($this->recoveries === 1, $this->model === PaymentProcessStatus::Recovered);
        Assert::same($this->process->recoveryKey(), expected: 'recover-payment-order-1');
    }
}
