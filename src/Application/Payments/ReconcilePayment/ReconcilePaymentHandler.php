<?php

declare(strict_types=1);

namespace App\Application\Payments\ReconcilePayment;

use App\Domain\Ordering\Exception\PaymentNotFailedYetException;
use App\Domain\Payments\ChargeFinding;
use App\Domain\Payments\Event\PaymentRecovered;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentOutcome;
use App\Domain\Payments\PaymentOutcomes;
use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessRepository;
use App\Domain\Payments\Process\PaymentProcessStatus;
use App\Domain\Payments\Process\PaymentProcessStep;
use App\Domain\Shared\Transaction\TransactionManager;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The saga's last step. A payment given up without an answer is asked about
 * at the bank; if the money was taken after all, the failed order is paid
 * (PaymentRecovered, which Ordering turns into RecoverPayment) and the
 * subscribers hear `order.paid` after `order.payment_failed`. Only an
 * abandoned process is reconciled: a redelivery after the outcome is settled
 * changes nothing.
 */
final readonly class ReconcilePaymentHandler
{
    public function __construct(
        private PaymentProcessRepository $processes,
        private PaymentGateway $gateway,
        private PaymentOutcomes $outcomes,
        private TransactionManager $transactions,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @throws GatewayUnavailableException the bank could not be asked; the outbox repeats the request
     * @throws PaymentNotFailedYetException the order has not failed yet; the outbox repeats the request
     */
    public function handle(ReconcilePaymentCommand $command): void
    {
        $process = $this->processes->find(orderId: $command->orderId);
        if (!$process instanceof PaymentProcess || $process->status() !== PaymentProcessStatus::Abandoned) {
            return;
        }

        // The bank call runs outside any transaction; the process moves after it.
        $charged = $this->gateway->find(orderId: $process->orderId(), gatewayKey: $process->gatewayKey()) === ChargeFinding::Charged;
        if ($this->settle(process: $process, step: $charged ? PaymentProcessStep::ConfirmCharged : PaymentProcessStep::ConfirmNotCharged) && $charged) {
            $this->outcomes->record(outcome: PaymentOutcome::Recovered);
        }
    }

    /**
     * Re-reads the process in a short transaction and applies the step to
     * what is stored now: a concurrent reconciliation may have settled it. A
     * found charge moves the order in the same transaction, so the saga
     * never ends recovered over an order that is still failed.
     */
    private function settle(PaymentProcess $process, PaymentProcessStep $step): bool
    {
        /** @var bool $applied */
        $applied = $this->transactions->execute(operation: function () use ($process, $step): bool {
            $current = $this->processes->find(orderId: $process->orderId());
            if (!$current instanceof PaymentProcess || $current->take(step: $step)->isErr()) {
                return false;
            }
            $this->processes->save(process: $current);
            if ($step === PaymentProcessStep::ConfirmCharged) {
                $this->events->dispatch(event: new PaymentRecovered(orderId: $current->orderId(), recoveryKey: $current->recoveryKey()));
            }

            return true;
        });

        return $applied;
    }
}
