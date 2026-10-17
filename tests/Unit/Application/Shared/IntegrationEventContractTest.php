<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Shared;

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Application\Shared\Integration\Exception\InvalidIntegrationEvent;
use App\Application\Shared\Integration\Exception\UnknownIntegrationEvent;
use App\Application\Shared\Integration\IntegrationEvent;
use App\Application\Shared\Integration\IntegrationEventBus;
use App\Application\Shared\Integration\IntegrationEventRegistry;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderTransition;
use App\Infrastructure\Ordering\Outbox\PublishOrderIntegrationEvents;
use App\Infrastructure\Shared\Correlation\CurrentRequestId;
use App\Tests\Support\IntegrationEvents;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Rasuvaeff\Yii3Telemetry\QueueTracing;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * The integration events are a contract between processes: what the producer
 * writes, the consumer must read back, and anything else must be refused
 * before it reaches a handler.
 */
#[Test]
#[Covers(IntegrationEventRegistry::class)]
#[Covers(PublishOrderIntegrationEvents::class)]
#[Covers(PaymentRequested::class)]
#[Covers(OrderPaid::class)]
#[Covers(OrderPaymentFailed::class)]
final class IntegrationEventContractTest
{
    /**
     * Producer → consumer: for any status change of any order, the event the
     * producer records parses back, through the registry, into an equal one.
     */
    #[Property(runs: 200)]
    public function whatTheProducerWritesTheConsumerReads(OrderTransition $transition, string $orderId, int $cents): void
    {
        Classify::cover($transition === OrderTransition::StartPayment, label: 'payment requested', minPercent: 20.0);
        $recorded = [];
        $bus = Understudy::for(IntegrationEventBus::class);
        when(static fn () => $bus->publish(Arg::any()))->answers(static function (Invocation $call) use (&$recorded): null {
            $event = $call->arg(parameter: 'event');
            \assert($event instanceof IntegrationEvent);
            $recorded[] = $event;

            return null;
        });
        $correlation = new CorrelationIdHolder();
        $producer = new PublishOrderIntegrationEvents(bus: $bus, queueTracing: new QueueTracing(NullTracer::instance()), requestId: new CurrentRequestId(holder: $correlation));

        $producer(new OrderStatusChanged(
            orderId: new OrderId(value: $orderId),
            amount: new Amount(cents: $cents),
            transition: $transition,
            from: $transition->source(),
            to: $transition->target(),
        ));

        Assert::same(count($recorded), expected: 1);
        $event = $recorded[0];
        $wire = json_encode(value: $event->toPayload(), flags: JSON_THROW_ON_ERROR);
        $read = IntegrationEvents::registry()->parse(type: $event::type(), payload: $wire);
        Assert::same($read::class, $event::class);
        Assert::same(json_encode(value: $read->toPayload(), flags: JSON_THROW_ON_ERROR), $wire);
        Assert::same($read->aggregateId(), $orderId);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function whatTheProducerWritesTheConsumerReadsGenerators(): array
    {
        return [
            'transition' => Gen::enum(OrderTransition::class),
            'orderId' => Gen::stringOf(minLength: 1, maxLength: OrderId::MAX_LENGTH),
            'cents' => Gen::intBetween(Amount::MIN_CENTS, Amount::MAX_CENTS),
        ];
    }

    /**
     * A payload outside its event's schema is refused, whichever event and
     * whichever field: the consumer never sees half an event.
     *
     * @param array<string, mixed> $payload
     */
    #[Property(runs: 200)]
    public function aPayloadOutsideTheSchemaIsRefused(string $type, array $payload): void
    {
        try {
            IntegrationEvents::registry()->parse(type: $type, payload: json_encode(value: $payload, flags: JSON_THROW_ON_ERROR));
            Assert::fail(message: 'A payload outside the schema must be refused');
        } catch (InvalidIntegrationEvent $refused) {
            Assert::same($refused->type, $type);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aPayloadOutsideTheSchemaIsRefusedGenerators(): array
    {
        $tooLong = Gen::stringOf(minLength: OrderId::MAX_LENGTH + 1, maxLength: 100);

        return [
            'type' => Gen::elements([PaymentRequested::type(), OrderPaid::type(), OrderPaymentFailed::type()]),
            'payload' => Gen::frequency([
                [1, Gen::elements([[], ['orderId' => ''], ['orderId' => 7], ['order' => 'o-1']])],
                [1, Gen::map($tooLong, static fn (string $id): array => ['orderId' => $id, 'amountCents' => 1, 'gatewayIdempotencyKey' => 'k'])],
            ]),
        ];
    }

    public function aRequestedPaymentNeedsItsAmountAndKey(): void
    {
        foreach ([['orderId' => 'o-1', 'gatewayIdempotencyKey' => 'k'], ['orderId' => 'o-1', 'amountCents' => 1], ['orderId' => 'o-1', 'amountCents' => 0, 'gatewayIdempotencyKey' => 'k']] as $payload) {
            try {
                IntegrationEvents::registry()->parse(type: PaymentRequested::type(), payload: json_encode(value: $payload, flags: JSON_THROW_ON_ERROR));
                Assert::fail(message: 'An incomplete payment request must be refused');
            } catch (InvalidIntegrationEvent $refused) {
                Assert::same($refused->type, PaymentRequested::type());
            }
        }
    }

    /** One character is an order id; the result events read it. */
    public function aResultOfAOneCharacterOrderIsRead(): void
    {
        foreach ([OrderPaid::type(), OrderPaymentFailed::type()] as $type) {
            Assert::same(IntegrationEvents::registry()->parse(type: $type, payload: '{"orderId":"a"}')->aggregateId(), expected: 'a');
        }
    }

    public function anUnknownTypeAndMalformedJsonAreRefused(): void
    {
        try {
            IntegrationEvents::registry()->parse(type: 'order.shipped', payload: '{}');
            Assert::fail(message: 'An unknown type must be refused');
        } catch (UnknownIntegrationEvent $unknown) {
            Assert::same($unknown->type, expected: 'order.shipped');
        }

        try {
            IntegrationEvents::registry()->parse(type: OrderPaid::type(), payload: '{"orderId":');
            Assert::fail(message: 'Malformed JSON must be refused');
        } catch (InvalidIntegrationEvent $malformed) {
            Assert::instanceOf($malformed->getPrevious(), \JsonException::class);
        }
    }

    /** One type, one class: registering a type twice is a wiring error. */
    public function aTypeIsRegisteredOnce(): void
    {
        try {
            new IntegrationEventRegistry(OrderPaid::class, OrderPaid::class);
            Assert::fail(message: 'A duplicate type must be refused');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), expected: 'Integration event type "order.paid" is registered twice');
        }
    }

    /**
     * The registry is the list of contracts the outbox carries; a new
     * version is a new entry, never an edit (`type@vN`).
     */
    public function theContractsAreTheFrozenOutboxTypes(): void
    {
        Assert::same(IntegrationEvents::registry()->contracts(), ['payment.requested@v1', 'order.paid@v1', 'order.payment_failed@v1', 'payment.reconciliation_requested@v1']);
    }

    /**
     * The bounds of a payment request, each side of each edge: the key fits
     * the gateway's 128, the correlation id the middleware's 128.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('paymentRequestBoundsProvider')]
    public function aPaymentRequestIsReadExactlyWithinItsBounds(array $payload, bool $valid): void
    {
        try {
            IntegrationEvents::registry()->parse(type: PaymentRequested::type(), payload: json_encode(value: $payload, flags: JSON_THROW_ON_ERROR));
            Assert::true($valid);
        } catch (InvalidIntegrationEvent) {
            Assert::false($valid);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function paymentRequestBoundsProvider(): iterable
    {
        $base = ['orderId' => 'o', 'amountCents' => 1, 'gatewayIdempotencyKey' => 'k'];
        yield 'empty order id' => [['orderId' => ''] + $base, false];
        yield 'empty key' => [['gatewayIdempotencyKey' => ''] + $base, false];
        yield 'key of 128' => [['gatewayIdempotencyKey' => str_repeat(string: 'k', times: 128)] + $base, true];
        yield 'key of 129' => [['gatewayIdempotencyKey' => str_repeat(string: 'k', times: 129)] + $base, false];
        yield 'one-character key' => [['gatewayIdempotencyKey' => 'k'] + $base, true];
        yield 'correlation id of 128' => [['correlationId' => str_repeat(string: 'c', times: 128)] + $base, true];
        yield 'correlation id of 129' => [['correlationId' => str_repeat(string: 'c', times: 129)] + $base, false];
    }

    /** The wire shape the worker of an older release reads. */
    public function aPaymentRequestGoesOnTheWireWithItsFrozenKeys(): void
    {
        Assert::same(
            new PaymentRequested(orderId: 'o-1', amountCents: 1999, gatewayIdempotencyKey: 'payment-o-1', correlationId: 'c-1', trace: ['traceparent' => 'x'])->toPayload(),
            ['orderId' => 'o-1', 'amountCents' => 1999, 'gatewayIdempotencyKey' => 'payment-o-1', 'correlationId' => 'c-1', 'trace' => ['traceparent' => 'x']],
        );
    }
}
