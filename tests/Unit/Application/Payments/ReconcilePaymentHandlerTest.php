<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Payments;

use App\Application\Payments\ReconcilePayment\ReconcilePaymentCommand;
use App\Application\Payments\ReconcilePayment\ReconcilePaymentHandler;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\Exception\PaymentNotFailedYetException;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\ChargeFinding;
use App\Domain\Payments\Event\PaymentRecovered;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentOutcome;
use App\Domain\Payments\PaymentOutcomes;
use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessStatus;
use App\Tests\Support\ImmediateTransactions;
use App\Tests\Support\InMemoryPaymentProcesses;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * The recovery, delivered at least once: whatever the process's status and
 * however often the reconciliation arrives, the bank is asked only about an
 * abandoned payment, a found charge pays the order exactly once, and a
 * settled payment is never touched.
 */
#[Test]
#[Covers(ReconcilePaymentHandler::class)]
final class ReconcilePaymentHandlerTest
{
    #[Property(runs: 200)]
    public function aFoundChargePaysTheOrderOnceWhateverTheRedeliveries(PaymentProcessStatus $status, bool $charged, int $deliveries): void
    {
        $abandoned = $status === PaymentProcessStatus::Abandoned;
        Classify::cover($abandoned, label: 'abandoned', minPercent: 10.0);
        Classify::cover($abandoned && $charged, label: 'abandoned and charged', minPercent: 5.0);
        $processes = $this->processes(status: $status);
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->find(Arg::any(), Arg::any()))->returns($charged ? ChargeFinding::Charged : ChargeFinding::NotCharged);
        $outcomes = Understudy::for(PaymentOutcomes::class);
        $events = Understudy::for(EventDispatcherInterface::class);
        $handler = new ReconcilePaymentHandler(processes: $processes, gateway: $gateway, outcomes: $outcomes, transactions: new ImmediateTransactions(), events: $events);

        for ($delivery = 0; $delivery < $deliveries; ++$delivery) {
            $handler->handle(command: new ReconcilePaymentCommand(orderId: new OrderId(value: 'order-1')));
        }

        Assert::same($processes->find(orderId: new OrderId(value: 'order-1'))?->status(), match (true) {
            $abandoned && $charged => PaymentProcessStatus::Recovered,
            $abandoned => PaymentProcessStatus::NotCharged,
            default => $status,
        });
        $recovered = $abandoned && $charged;
        verify(static fn () => $events->dispatch(Arg::satisfies(
            static fn (mixed $event): bool => $event instanceof PaymentRecovered && $event->orderId->value === 'order-1' && $event->recoveryKey === 'recover-payment-order-1',
        )), times: $recovered ? 1 : null, never: !$recovered);
        verify(static fn () => $events->dispatch(Arg::any()), times: $recovered ? 1 : null, never: !$recovered);
        verify(static fn () => $gateway->find(Arg::any(), 'payment-order-1'), times: $abandoned ? 1 : null, never: !$abandoned);
        verify(static fn () => $outcomes->record(PaymentOutcome::Recovered), times: $recovered ? 1 : null, never: !$recovered);
        verify(static fn () => $outcomes->record(Arg::any()), times: $recovered ? 1 : null, never: !$recovered);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aFoundChargePaysTheOrderOnceWhateverTheRedeliveriesGenerators(): array
    {
        return [
            'status' => Gen::frequency([[3, Gen::elements([PaymentProcessStatus::Abandoned])], [2, Gen::enum(PaymentProcessStatus::class)]]),
            'charged' => Gen::bool(),
            'deliveries' => Gen::intBetween(min: 1, max: 3),
        ];
    }

    /**
     * A charge found before the decline failed the order: the order refuses,
     * the exception leaves the handler for the outbox to repeat, and nothing
     * is counted as recovered.
     */
    public function aRecoveryTheOrderIsNotReadyForIsRepeated(): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->find(Arg::any(), Arg::any()))->returns(ChargeFinding::Charged);
        $events = Understudy::for(EventDispatcherInterface::class);
        when(static fn () => $events->dispatch(Arg::any()))->throws(new PaymentNotFailedYetException(orderId: new OrderId(value: 'order-1')));
        $outcomes = Understudy::for(PaymentOutcomes::class);
        $handler = new ReconcilePaymentHandler(processes: $this->processes(status: PaymentProcessStatus::Abandoned), gateway: $gateway, outcomes: $outcomes, transactions: new ImmediateTransactions(), events: $events);

        try {
            $handler->handle(command: new ReconcilePaymentCommand(orderId: new OrderId(value: 'order-1')));
            Assert::fail(message: 'A recovery the order refused must not be swallowed');
        } catch (PaymentNotFailedYetException $exception) {
            Assert::same($exception->orderId->value, expected: 'order-1');
        }
        verify(static fn () => $outcomes->record(Arg::any()), never: true);
    }

    private function processes(PaymentProcessStatus $status): InMemoryPaymentProcesses
    {
        $processes = new InMemoryPaymentProcesses();
        $processes->addIfAbsent(process: PaymentProcess::restore(orderId: new OrderId(value: 'order-1'), amount: new Amount(cents: 5040), gatewayKey: 'payment-order-1', status: $status));

        return $processes;
    }
}
