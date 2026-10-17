<?php

declare(strict_types=1);

namespace App\Domain\Payments\Process;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use Rasuvaeff\Result\Result;

/**
 * The payment of one order as a process manager (saga): it remembers what
 * the bank was asked and what came back, across deliveries and processes.
 * When the last attempt goes unanswered the outcome is unknown; the process
 * then reconciles with the bank and, if the money was taken after all, the
 * failed order is paid — the customer keeps what they paid for.
 */
final class PaymentProcess
{
    /**
     * @param non-empty-string $gatewayKey the charge's idempotency key at the bank
     */
    private function __construct(
        private readonly OrderId $orderId,
        private readonly Amount $amount,
        private readonly string $gatewayKey,
        private PaymentProcessStatus $status,
    ) {}

    /**
     * @param non-empty-string $gatewayKey
     */
    public static function start(OrderId $orderId, Amount $amount, string $gatewayKey): self
    {
        return new self(orderId: $orderId, amount: $amount, gatewayKey: $gatewayKey, status: PaymentProcessStatus::Requested);
    }

    /**
     * @param non-empty-string $gatewayKey
     */
    public static function restore(OrderId $orderId, Amount $amount, string $gatewayKey, PaymentProcessStatus $status): self
    {
        return new self(orderId: $orderId, amount: $amount, gatewayKey: $gatewayKey, status: $status);
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function amount(): Amount
    {
        return $this->amount;
    }

    /**
     * @return non-empty-string
     */
    public function gatewayKey(): string
    {
        return $this->gatewayKey;
    }

    /**
     * The key the order's recovery is applied under, derived from the
     * charge's: a reconciliation delivered twice moves the order once.
     *
     * @return non-empty-string
     */
    public function recoveryKey(): string
    {
        return 'recover-' . $this->gatewayKey;
    }

    public function status(): PaymentProcessStatus
    {
        return $this->status;
    }

    /**
     * @return Result<PaymentProcessStatus, StepRefused>
     */
    #[\NoDiscard('A refused step is a value; ignoring it loses the refusal')]
    public function take(PaymentProcessStep $step): Result
    {
        if ($this->status !== $step->source()) {
            return Result::err(new StepRefused(orderId: $this->orderId, step: $step, status: $this->status));
        }
        $this->status = $step->target();

        return Result::ok($this->status);
    }
}
