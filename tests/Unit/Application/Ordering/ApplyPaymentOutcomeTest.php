<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ordering;

use App\Application\Ordering\Event\ApplyPaymentOutcome;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\Exception\PaymentNotFailedYetException;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Payments\Event\PaymentCaptured;
use App\Domain\Payments\Event\PaymentDeclined;
use App\Domain\Payments\Event\PaymentRecovered;
use App\Domain\Shared\Transaction\TransactionManager;
use App\Tests\Support\InMemoryOrderRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * Ordering's side of a payment outcome: the event names the transition, the
 * attempt key is the lifecycle's idempotency key, and the order is saved and
 * its events published only when the lifecycle applied the transition now.
 */
#[Test]
#[Covers(ApplyPaymentOutcome::class)]
final class ApplyPaymentOutcomeTest
{
    #[DataProvider('outcomeProvider')]
    public function anOutcomeMovesTheOrderAndPublishesTheChange(string $outcome, OrderTransition $transition, OrderStatus $status): void
    {
        $orders = new InMemoryOrderRepository($this->processingOrder());
        $events = Understudy::for(EventDispatcherInterface::class);
        $listener = new ApplyPaymentOutcome(orders: $orders, lifecycle: $this->applyingLifecycle(), transactions: $this->transactions(), events: $events);

        $outcome === 'captured'
            ? $listener->onCaptured(event: new PaymentCaptured(orderId: new OrderId(value: 'order-1'), attemptKey: 'attempt-1'))
            : $listener->onDeclined(event: new PaymentDeclined(orderId: new OrderId(value: 'order-1'), attemptKey: 'attempt-1'));

        Assert::same($orders->find(id: new OrderId(value: 'order-1'))?->status(), $status);
        Assert::same($orders->saved, ['order-1']);
        verify(static fn () => $events->dispatch(Arg::satisfies(
            static fn (mixed $event): bool => $event instanceof OrderStatusChanged && $event->transition === $transition,
        )), times: 1);
    }

    public static function outcomeProvider(): iterable
    {
        yield 'captured pays' => ['captured', OrderTransition::MarkPaid, OrderStatus::Paid];
        yield 'declined fails' => ['declined', OrderTransition::MarkFailed, OrderStatus::PaymentFailed];
    }

    /** The reconciliation found the money: the failed order is paid, and says so once. */
    public function aRecoveryPaysAFailedOrder(): void
    {
        $orders = new InMemoryOrderRepository($this->order(status: OrderStatus::PaymentFailed));
        $events = Understudy::for(EventDispatcherInterface::class);

        new ApplyPaymentOutcome(orders: $orders, lifecycle: $this->applyingLifecycle(), transactions: $this->transactions(), events: $events)
            ->onRecovered(event: new PaymentRecovered(orderId: new OrderId(value: 'order-1'), recoveryKey: 'recover-1'));

        Assert::same($orders->find(id: new OrderId(value: 'order-1'))?->status(), OrderStatus::Paid);
        Assert::same($orders->saved, ['order-1']);
        verify(static fn () => $events->dispatch(Arg::satisfies(
            static fn (mixed $event): bool => $event instanceof OrderStatusChanged && $event->transition === OrderTransition::RecoverPayment,
        )), times: 1);
    }

    public function theRecoveryKeyIsTheLifecycleIdempotencyKey(): void
    {
        $lifecycle = Understudy::for(OrderLifecycle::class);
        when(static fn () => $lifecycle->apply(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->returns(false);

        new ApplyPaymentOutcome(orders: new InMemoryOrderRepository($this->order(status: OrderStatus::Paid)), lifecycle: $lifecycle, transactions: $this->transactions(), events: Understudy::for(EventDispatcherInterface::class))
            ->onRecovered(event: new PaymentRecovered(orderId: new OrderId(value: 'order-1'), recoveryKey: 'recover-7'));

        verify(static fn () => $lifecycle->apply(Arg::any(), OrderTransition::RecoverPayment, 'recover-7', Arg::any()), times: 1);
    }

    /**
     * The charge was found before the decline failed the order: refused by
     * throwing, so the reconciliation's transaction rolls back and repeats.
     */
    public function aRecoveryBeforeTheOrderFailedIsRepeatedLater(): void
    {
        $orders = new InMemoryOrderRepository($this->processingOrder());
        $lifecycle = Understudy::for(OrderLifecycle::class);
        $listener = new ApplyPaymentOutcome(orders: $orders, lifecycle: $lifecycle, transactions: $this->transactions(), events: Understudy::for(EventDispatcherInterface::class));

        try {
            $listener->onRecovered(event: new PaymentRecovered(orderId: new OrderId(value: 'order-1'), recoveryKey: 'recover-1'));
            Assert::fail(message: 'A recovery of a processing order must not be applied or swallowed');
        } catch (PaymentNotFailedYetException $exception) {
            Assert::same($exception->orderId->value, expected: 'order-1');
        }
        Assert::same($orders->saved, []);
        verify(static fn () => $lifecycle->apply(Arg::any(), Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    /** Only the recovery waits for the decline: a capture of a processing order goes ahead. */
    public function onlyTheRecoveryWaitsForTheOrderToFail(): void
    {
        $orders = new InMemoryOrderRepository($this->processingOrder());

        new ApplyPaymentOutcome(orders: $orders, lifecycle: $this->applyingLifecycle(), transactions: $this->transactions(), events: Understudy::for(EventDispatcherInterface::class))
            ->onCaptured(event: new PaymentCaptured(orderId: new OrderId(value: 'order-1'), attemptKey: 'attempt-1'));

        Assert::same($orders->find(id: new OrderId(value: 'order-1'))?->status(), OrderStatus::Paid);
    }

    public function theAttemptKeyIsTheLifecycleIdempotencyKey(): void
    {
        $lifecycle = Understudy::for(OrderLifecycle::class);
        when(static fn () => $lifecycle->apply(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->returns(false);

        new ApplyPaymentOutcome(orders: new InMemoryOrderRepository($this->processingOrder()), lifecycle: $lifecycle, transactions: $this->transactions(), events: Understudy::for(EventDispatcherInterface::class))
            ->onCaptured(event: new PaymentCaptured(orderId: new OrderId(value: 'order-1'), attemptKey: 'attempt-7'));

        verify(static fn () => $lifecycle->apply(Arg::any(), OrderTransition::MarkPaid, 'attempt-7', Arg::any()), times: 1);
    }

    /** A redelivered outcome the lifecycle has already seen changes nothing. */
    public function aRepeatedOutcomeIsNotSavedOrPublishedAgain(): void
    {
        $orders = new InMemoryOrderRepository($this->processingOrder());
        $lifecycle = Understudy::for(OrderLifecycle::class);
        when(static fn () => $lifecycle->apply(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->returns(false);
        $events = Understudy::for(EventDispatcherInterface::class);

        new ApplyPaymentOutcome(orders: $orders, lifecycle: $lifecycle, transactions: $this->transactions(), events: $events)
            ->onDeclined(event: new PaymentDeclined(orderId: new OrderId(value: 'order-1'), attemptKey: 'attempt-1'));

        Assert::same($orders->saved, []);
        verify(static fn () => $events->dispatch(Arg::any()), never: true);
    }

    public function anOutcomeForAMissingOrderFails(): void
    {
        $listener = new ApplyPaymentOutcome(orders: new InMemoryOrderRepository(), lifecycle: $this->applyingLifecycle(), transactions: $this->transactions(), events: Understudy::for(EventDispatcherInterface::class));

        try {
            $listener->onCaptured(event: new PaymentCaptured(orderId: new OrderId(value: 'order-1'), attemptKey: 'attempt-1'));
            Assert::fail(message: 'An outcome for no order must not be swallowed');
        } catch (OrderNotFoundException $exception) {
            Assert::same($exception->orderId->value, expected: 'order-1');
        }
    }

    private function processingOrder(): Order
    {
        return $this->order(status: OrderStatus::Processing);
    }

    private function order(OrderStatus $status): Order
    {
        return Order::restore(id: new OrderId(value: 'order-1'), customerId: new CustomerId(value: 'c-1'), amount: new Amount(cents: 1999), status: $status);
    }

    /** A lifecycle that applies every transition and then runs the callback, as the workflow does. */
    private function applyingLifecycle(): OrderLifecycle
    {
        $lifecycle = Understudy::for(OrderLifecycle::class);
        when(static fn () => $lifecycle->apply(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->answers(static function (Invocation $call): bool {
            $order = $call->arg(parameter: 'order');
            $transition = $call->arg(parameter: 'transition');
            $then = $call->arg(parameter: 'then');
            \assert($order instanceof Order && $transition instanceof OrderTransition && is_callable($then));
            if ($order->apply(transition: $transition)->isErr()) {
                throw new \LogicException(message: 'The double was asked for a transition the order refuses');
            }
            $then();

            return true;
        });

        return $lifecycle;
    }

    private function transactions(): TransactionManager
    {
        $transactions = Understudy::for(TransactionManager::class);
        when(static fn () => $transactions->execute(Arg::any()))->answers(static function (Invocation $call): mixed {
            $operation = $call->arg(parameter: 'operation');
            \assert(is_callable($operation));

            return $operation();
        });

        return $transactions;
    }
}
