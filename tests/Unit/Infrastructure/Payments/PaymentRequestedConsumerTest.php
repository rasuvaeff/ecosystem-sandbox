<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payments;

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Application\Payments\CapturePayment\CapturePaymentHandler;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Payments\ChargeResult;
use App\Domain\Payments\Event\PaymentCaptured;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentOutcomes;
use App\Infrastructure\Payments\Outbox\PaymentRequestedConsumer;
use App\Tests\Support\ImmediateTransactions;
use App\Tests\Support\InMemoryPaymentProcesses;
use App\Tests\Support\IntegrationEvents;
use App\Tests\Support\OrderingApis;
use App\Tests\Support\RecordingBus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Rasuvaeff\Yii3Telemetry\QueueTracing;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * The outbox side of a payment: which failures the outbox repeats and which
 * it gives up, and the correlation id carried across the process boundary.
 */
#[Test]
#[Covers(PaymentRequestedConsumer::class)]
final class PaymentRequestedConsumerTest
{
    private const string CORRELATION_ID = '8f14e45f-ceea-4167-a1e3-5bbb4e8f4e4e';

    #[DataProvider('failureProvider')]
    public function aGatewayFailureIsRetriedOnlyWhileItCanSucceed(\Throwable $thrown, bool $terminal): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->charge(Arg::any()))->throws($thrown);

        try {
            $this->consumer(gateway: $gateway)->publish($this->message());
            Assert::fail(message: 'A failed charge must be reported to the outbox');
        } catch (PublishException $exception) {
            Assert::same($exception->isTerminal(), $terminal);
        }
    }

    public static function failureProvider(): iterable
    {
        yield 'unavailable is repeated' => [new GatewayUnavailableException(message: 'down'), false];
        yield 'rejected is given up' => [new ChargeRejectedException(message: 'HTTP 422'), true];
    }

    public function anUnsupportedTypeIsTerminal(): void
    {
        try {
            $this->consumer(gateway: Understudy::for(PaymentGateway::class))
                ->publish(OutboxMessage::create(type: OrderPaid::type(), payload: '{}', id: 'unsupported-1'));
            Assert::fail(message: 'An unsupported type must not be processed');
        } catch (PublishException $exception) {
            Assert::true($exception->isTerminal());
        }
    }

    /**
     * A payload is data the worker does not control: anything without the
     * order id and the gateway key is given up and never charged.
     */
    #[Property(runs: 100)]
    public function aPayloadWithoutTheRequiredFieldsIsGivenUpUncharged(string $payload): void
    {
        $gateway = Understudy::for(PaymentGateway::class);

        try {
            $this->consumer(gateway: $gateway)->publish(OutboxMessage::create(type: PaymentRequested::type(), payload: $payload, id: 'payment-1'));
            Assert::fail(message: 'An unusable payload must not be processed');
        } catch (PublishException $exception) {
            Assert::true($exception->isTerminal());
        }

        verify(static fn () => $gateway->charge(Arg::any()), never: true);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aPayloadWithoutTheRequiredFieldsIsGivenUpUnchargedGenerators(): array
    {
        return ['payload' => Gen::frequency([
            [1, Gen::stringOf(maxLength: 40)],
            [2, Gen::map(Gen::json(), static fn (mixed $json): string => json_encode($json, JSON_THROW_ON_ERROR))],
            [1, Gen::elements([
                '{"orderId":"order-1"}',
                '{"gatewayIdempotencyKey":"payment-order-1"}',
                '{"orderId":"","gatewayIdempotencyKey":"payment-order-1"}',
                '{"orderId":1,"gatewayIdempotencyKey":"payment-order-1"}',
            ])],
        ])];
    }

    /** The correlation scope the checkout opened is reopened around the charge. */
    public function theChargeRunsUnderTheCheckoutsCorrelationId(): void
    {
        $correlation = new CorrelationIdHolder();
        $seen = null;
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->charge(Arg::any()))->answers(static function () use ($correlation, &$seen): ChargeResult {
            $seen = $correlation->get();

            return ChargeResult::Approved;
        });

        $this->consumer(gateway: $gateway, correlation: $correlation)->publish($this->message());

        Assert::same($seen, self::CORRELATION_ID);
    }

    public function aSuccessfulChargePublishesTheCapture(): void
    {
        $gateway = Understudy::for(PaymentGateway::class);
        when(static fn () => $gateway->charge(Arg::any()))->returns(ChargeResult::Approved);
        $events = Understudy::for(EventDispatcherInterface::class);

        $this->consumer(gateway: $gateway, events: $events)->publish($this->message());

        verify(static fn () => $events->dispatch(Arg::satisfies(
            static fn (mixed $event): bool => $event instanceof PaymentCaptured && $event->attemptKey === 'payment-transition-payment-1',
        )), times: 1);
    }

    private function consumer(
        PaymentGateway $gateway,
        ?EventDispatcherInterface $events = null,
        ?CorrelationIdHolder $correlation = null,
    ): PaymentRequestedConsumer {
        $order = Order::restore(id: new OrderId(value: 'order-1'), customerId: new CustomerId(value: 'customer-1'), amount: new Amount(cents: 1999), status: OrderStatus::Processing);

        return new PaymentRequestedConsumer(
            capture: new CapturePaymentHandler(
                ordering: OrderingApis::over($order),
                gateway: $gateway,
                outcomes: Understudy::for(PaymentOutcomes::class),
                events: $events ?? Understudy::for(EventDispatcherInterface::class),
                processes: new InMemoryPaymentProcesses(),
                transactions: new ImmediateTransactions(),
                bus: new RecordingBus(),
            ),
            messages: IntegrationEvents::messages(),
            correlation: $correlation ?? new CorrelationIdHolder(),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            queueTracing: new QueueTracing(NullTracer::instance()),
        );
    }

    private function message(int $attempts = 1): OutboxMessage
    {
        $message = OutboxMessage::create(
            type: PaymentRequested::type(),
            payload: json_encode(new PaymentRequested(
                orderId: 'order-1',
                amountCents: 1999,
                gatewayIdempotencyKey: 'payment-order-1',
                correlationId: self::CORRELATION_ID,
            )->toPayload(), JSON_THROW_ON_ERROR),
            id: 'payment-1',
        );
        // The Processor counts an attempt before it calls the publisher.
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $message = $message->withAttempt(new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'))->now());
        }

        return $message;
    }
}
