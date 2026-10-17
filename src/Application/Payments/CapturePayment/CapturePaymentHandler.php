<?php

declare(strict_types=1);

namespace App\Application\Payments\CapturePayment;

use App\Application\Ordering\OrderingApi;
use App\Application\Payments\Integration\PaymentReconciliationRequested;
use App\Application\Shared\Integration\IntegrationEventBus;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Charge;
use App\Domain\Payments\ChargeResult;
use App\Domain\Payments\Event\PaymentCaptured;
use App\Domain\Payments\Event\PaymentDeclined;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentOutcome;
use App\Domain\Payments\PaymentOutcomes;
use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessRepository;
use App\Domain\Payments\Process\PaymentProcessStep;
use App\Domain\Shared\Transaction\TransactionManager;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Takes the money for one order and says what happened: PaymentCaptured or
 * PaymentDeclined, which Ordering turns into the order's next status. A
 * rejected request and the last unanswered attempt are given up (declined);
 * an earlier unanswered attempt is left to the outbox to repeat.
 */
final readonly class CapturePaymentHandler
{
    public function __construct(
        private OrderingApi $ordering,
        private PaymentGateway $gateway,
        private PaymentOutcomes $outcomes,
        private EventDispatcherInterface $events,
        private PaymentProcessRepository $processes,
        private TransactionManager $transactions,
        private IntegrationEventBus $bus,
    ) {}

    /**
     * @throws ChargeRejectedException after declining the payment
     * @throws GatewayUnavailableException after declining it when this was the last attempt
     */
    public function handle(CapturePaymentCommand $command): void
    {
        $order = $this->ordering->order(orderId: $command->orderId);
        // The process remembers the payment across deliveries; the first
        // delivery opens it.
        $this->transactions->execute(operation: fn (): bool => $this->processes->addIfAbsent(
            process: PaymentProcess::start(orderId: $order->orderId, amount: $order->amount, gatewayKey: $command->gatewayIdempotencyKey),
        ));

        try {
            $result = $this->gateway->charge(charge: new Charge(
                orderId: $order->orderId,
                amount: $order->amount,
                idempotencyKey: $command->gatewayIdempotencyKey,
            ));
        } catch (ChargeRejectedException $exception) {
            $this->outcomes->record(outcome: PaymentOutcome::Rejected);
            // A refused request took no money: declined, nothing to reconcile.
            $this->step(orderId: $command->orderId, step: PaymentProcessStep::Decline);
            $this->giveUp(command: $command);

            throw $exception;
        } catch (GatewayUnavailableException $exception) {
            $this->outcomes->record(outcome: PaymentOutcome::Rejected);
            // Checkout never restarts a non-pending order: with no attempt
            // left, the order has to leave `processing` with this one.
            if ($command->lastAttempt) {
                // No answer at all: the bank may have charged. The process is
                // abandoned, and that asks for a reconciliation.
                $this->step(orderId: $command->orderId, step: PaymentProcessStep::Abandon);
                $this->giveUp(command: $command);
            }

            throw $exception;
        }

        if ($result === ChargeResult::Approved) {
            $this->step(orderId: $command->orderId, step: PaymentProcessStep::Capture);
            $this->outcomes->record(outcome: PaymentOutcome::Paid);
            $this->events->dispatch(event: new PaymentCaptured(orderId: $command->orderId, attemptKey: $command->attemptKey));

            return;
        }
        $this->step(orderId: $command->orderId, step: PaymentProcessStep::Decline);
        $this->outcomes->record(outcome: PaymentOutcome::Failed);
        $this->events->dispatch(event: new PaymentDeclined(orderId: $command->orderId, attemptKey: $command->attemptKey));
    }

    /**
     * Moves the process in a short transaction of its own, never across the
     * bank call. A step the process refuses is a redelivery that found it
     * already moved: nothing to do. Abandoning records the reconciliation
     * request with the step.
     */
    private function step(OrderId $orderId, PaymentProcessStep $step): void
    {
        $this->transactions->execute(operation: function () use ($orderId, $step): void {
            $process = $this->processes->find(orderId: $orderId);
            if (!$process instanceof PaymentProcess || $process->take(step: $step)->isErr()) {
                return;
            }
            $this->processes->save(process: $process);
            if ($step === PaymentProcessStep::Abandon) {
                $this->bus->publish(event: new PaymentReconciliationRequested(orderId: $orderId->value));
            }
        });
    }

    /**
     * The gateway call carries a stable idempotency key, so a charge that did
     * land unseen is not repeated by any later request for this order.
     */
    private function giveUp(CapturePaymentCommand $command): void
    {
        $this->outcomes->record(outcome: PaymentOutcome::Abandoned);
        $this->events->dispatch(event: new PaymentDeclined(orderId: $command->orderId, attemptKey: $command->attemptKey));
    }
}
