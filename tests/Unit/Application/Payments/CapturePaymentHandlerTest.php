<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Payments;

use App\Application\Payments\CapturePayment\CapturePaymentCommand;
use App\Application\Payments\CapturePayment\CapturePaymentHandler;
use App\Application\Payments\Integration\PaymentReconciliationRequested;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Payments\Charge;
use App\Domain\Payments\ChargeResult;
use App\Domain\Payments\Event\PaymentCaptured;
use App\Domain\Payments\Event\PaymentDeclined;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentOutcome;
use App\Domain\Payments\PaymentOutcomes;
use App\Domain\Payments\Process\PaymentProcessStatus;
use App\Tests\Support\ImmediateTransactions;
use App\Tests\Support\InMemoryPaymentProcesses;
use App\Tests\Support\OrderingApis;
use App\Tests\Support\RecordingBus;
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
 * What the gateway did, as Payments tells Ordering: an approval is
 * PaymentCaptured, a decline or a given-up attempt PaymentDeclined, and an
 * unanswered attempt that the outbox will repeat is nothing at all.
 */
#[Test]
#[Covers(CapturePaymentHandler::class)]
final class CapturePaymentHandlerTest
{
    private const string GATEWAY_KEY = 'payment-order-1';
    private const string ATTEMPT_KEY = 'payment-transition-1';

    /**
     * Every gateway reaction against every attempt position: exactly one
     * outcome event or none, never both, and none only for an unanswered
     * attempt the outbox will repeat.
     *
     * @param 'approved'|'declined'|'rejected'|'unavailable' $reaction
     */
    #[Property(runs: 100)]
    public function eachChargeEndsInAtMostOneOutcome(string $reaction, bool $lastAttempt): void
    {
        Classify::cover($reaction === 'unavailable', label: 'unavailable', minPercent: 10.0);
        Classify::cover($lastAttempt, label: 'last attempt', minPercent: 20.0);
        $gateway = Understudy::for(PaymentGateway::class);
        $charge = when(static fn () => $gateway->charge(Arg::any()));
        match ($reaction) {
            'approved' => $charge->returns(ChargeResult::Approved),
            'declined' => $charge->returns(ChargeResult::Declined),
            'rejected' => $charge->throws(new ChargeRejectedException(message: 'HTTP 422')),
            'unavailable' => $charge->throws(new GatewayUnavailableException(message: 'down')),
        };
        $events = Understudy::for(EventDispatcherInterface::class);
        $processes = new InMemoryPaymentProcesses();
        $bus = new RecordingBus();
        $outcomes = Understudy::for(PaymentOutcomes::class);

        try {
            $this->handler(gateway: $gateway, events: $events, outcomes: $outcomes, processes: $processes, bus: $bus)->handle(command: $this->command(lastAttempt: $lastAttempt));
            Assert::true(in_array($reaction, ['approved', 'declined'], strict: true));
        } catch (ChargeRejectedException|GatewayUnavailableException) {
            Assert::true(in_array($reaction, ['rejected', 'unavailable'], strict: true));
        }

        $captured = $reaction === 'approved';
        $declined = $reaction === 'declined' || $reaction === 'rejected' || ($reaction === 'unavailable' && $lastAttempt);
        verify(static fn () => $events->dispatch(Arg::instanceOf(PaymentCaptured::class)), times: $captured ? 1 : null, never: !$captured);
        verify(static fn () => $events->dispatch(Arg::instanceOf(PaymentDeclined::class)), times: $declined ? 1 : null, never: !$declined);

        // The saga's state: a definite answer settles it; only an unanswered
        // last attempt abandons it, and only that asks for a reconciliation.
        $abandoned = $reaction === 'unavailable' && $lastAttempt;
        Assert::same($processes->find(orderId: new OrderId(value: 'order-1'))?->status(), match (true) {
            $captured => PaymentProcessStatus::Captured,
            $abandoned => PaymentProcessStatus::Abandoned,
            $declined => PaymentProcessStatus::Declined,
            default => PaymentProcessStatus::Requested,
        });
        Assert::same(array_map(static fn (object $event): string => $event::class, $bus->published), $abandoned ? [PaymentReconciliationRequested::class] : []);

        // The outcome metric: one sample per reaction, plus the abandonment.
        $expected = match ($reaction) {
            'approved' => [PaymentOutcome::Paid],
            'declined' => [PaymentOutcome::Failed],
            'rejected' => [PaymentOutcome::Rejected, PaymentOutcome::Abandoned],
            'unavailable' => $lastAttempt ? [PaymentOutcome::Rejected, PaymentOutcome::Abandoned] : [PaymentOutcome::Rejected],
        };
        foreach (PaymentOutcome::cases() as $outcome) {
            $times = count(array_filter($expected, static fn (PaymentOutcome $e): bool => $e === $outcome));
            verify(static fn () => $outcomes->record($outcome), times: $times > 0 ? $times : null, never: $times === 0);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function eachChargeEndsInAtMostOneOutcomeGenerators(): array
    {
        return [
            'reaction' => Gen::elements(['approved', 'declined', 'rejected', 'unavailable']),
            'lastAttempt' => Gen::bool(),
        ];
    }

    public function theChargeCarriesTheStoredOrderAndTheStableKey(): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        $charge = Arg::captor(Charge::class);
        when(static fn () => $gateway->charge($charge->capture()))->returns(ChargeResult::Approved);

        $this->handler(gateway: $gateway)->handle(command: $this->command(lastAttempt: false));

        Assert::same($charge->last()->orderId->value, expected: 'order-1');
        Assert::same($charge->last()->amount->cents, expected: 1999);
        Assert::same($charge->last()->idempotencyKey, self::GATEWAY_KEY);
    }

    public function theOutcomeCarriesTheAttemptKey(): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->charge(Arg::any()))->returns(ChargeResult::Approved);
        $events = Understudy::for(EventDispatcherInterface::class);

        $this->handler(gateway: $gateway, events: $events)->handle(command: $this->command(lastAttempt: false));

        verify(static fn () => $events->dispatch(Arg::satisfies(
            static fn (mixed $event): bool => $event instanceof PaymentCaptured && $event->attemptKey === self::ATTEMPT_KEY && $event->orderId->value === 'order-1',
        )), times: 1);
    }

    public function aGivenUpPaymentIsCountedAsAbandoned(): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->charge(Arg::any()))->throws(new GatewayUnavailableException(message: 'down'));
        $outcomes = Understudy::for(PaymentOutcomes::class);

        try {
            $this->handler(gateway: $gateway, outcomes: $outcomes)->handle(command: $this->command(lastAttempt: true));
            Assert::fail(message: 'An unavailable gateway must still be reported to the outbox');
        } catch (GatewayUnavailableException) {
        }

        verify(static fn () => $outcomes->record(PaymentOutcome::Abandoned), times: 1);
        verify(static fn () => $outcomes->record(PaymentOutcome::Paid), never: true);
    }

    public function aMissingOrderIsNeverCharged(): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        $handler = new CapturePaymentHandler(
            ordering: OrderingApis::over(),
            gateway: $gateway,
            outcomes: Understudy::for(PaymentOutcomes::class),
            events: Understudy::for(EventDispatcherInterface::class),
            processes: new InMemoryPaymentProcesses(),
            transactions: new ImmediateTransactions(),
            bus: new RecordingBus(),
        );

        try {
            $handler->handle(command: $this->command(lastAttempt: true));
            Assert::fail(message: 'A missing order must not be charged');
        } catch (OrderNotFoundException) {
        }

        verify(static fn () => $gateway->charge(Arg::any()), never: true);
    }

    private function handler(
        PaymentGateway $gateway,
        ?EventDispatcherInterface $events = null,
        ?PaymentOutcomes $outcomes = null,
        ?InMemoryPaymentProcesses $processes = null,
        ?RecordingBus $bus = null,
    ): CapturePaymentHandler {
        $order = Order::restore(
            id: new OrderId(value: 'order-1'),
            customerId: new CustomerId(value: 'customer-1'),
            amount: new Amount(cents: 1999),
            status: OrderStatus::Processing,
        );

        return new CapturePaymentHandler(
            ordering: OrderingApis::over($order),
            gateway: $gateway,
            outcomes: $outcomes ?? Understudy::for(PaymentOutcomes::class),
            events: $events ?? Understudy::for(EventDispatcherInterface::class),
            processes: $processes ?? new InMemoryPaymentProcesses(),
            transactions: new ImmediateTransactions(),
            bus: $bus ?? new RecordingBus(),
        );
    }

    private function command(bool $lastAttempt): CapturePaymentCommand
    {
        return new CapturePaymentCommand(
            orderId: new OrderId(value: 'order-1'),
            gatewayIdempotencyKey: self::GATEWAY_KEY,
            attemptKey: self::ATTEMPT_KEY,
            lastAttempt: $lastAttempt,
        );
    }
}
