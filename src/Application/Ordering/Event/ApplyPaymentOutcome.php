<?php

declare(strict_types=1);

namespace App\Application\Ordering\Event;

use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\Exception\PaymentNotFailedYetException;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderRepository;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Payments\Event\PaymentCaptured;
use App\Domain\Payments\Event\PaymentDeclined;
use App\Domain\Payments\Event\PaymentRecovered;
use App\Domain\Shared\Transaction\TransactionManager;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Ordering's side of a payment outcome: Payments says what the gateway did,
 * the order moves through its own lifecycle. The attempt key makes a
 * redelivered outcome a no-op.
 */
final readonly class ApplyPaymentOutcome
{
    public function __construct(
        private OrderRepository $orders,
        private OrderLifecycle $lifecycle,
        private TransactionManager $transactions,
        private EventDispatcherInterface $events,
    ) {}

    public function onCaptured(PaymentCaptured $event): void
    {
        $this->move(orderId: $event->orderId, transition: OrderTransition::MarkPaid, attemptKey: $event->attemptKey);
    }

    public function onDeclined(PaymentDeclined $event): void
    {
        $this->move(orderId: $event->orderId, transition: OrderTransition::MarkFailed, attemptKey: $event->attemptKey);
    }

    /**
     * Runs inside the reconciliation's transaction, so the saga and the order
     * move together or not at all.
     *
     * @throws PaymentNotFailedYetException the decline has not reached the order yet
     */
    public function onRecovered(PaymentRecovered $event): void
    {
        $this->move(orderId: $event->orderId, transition: OrderTransition::RecoverPayment, attemptKey: $event->recoveryKey);
    }

    /**
     * @param non-empty-string $attemptKey
     */
    private function move(OrderId $orderId, OrderTransition $transition, string $attemptKey): void
    {
        $this->transactions->execute(operation: function () use ($orderId, $transition, $attemptKey): void {
            $order = $this->orders->findForUpdate(id: $orderId) ?? throw new OrderNotFoundException(orderId: $orderId);
            // The decline and the reconciliation travel apart: the charge can
            // be found before the give-up has failed the order.
            if ($transition === OrderTransition::RecoverPayment && $order->status() === OrderStatus::Processing) {
                throw new PaymentNotFailedYetException(orderId: $orderId);
            }
            $this->lifecycle->apply(
                order: $order,
                transition: $transition,
                idempotencyKey: $attemptKey,
                then: function () use ($order): void {
                    $this->orders->save(order: $order);
                    foreach ($order->releaseEvents() as $event) {
                        $this->events->dispatch(event: $event);
                    }
                },
            );
        });
    }
}
