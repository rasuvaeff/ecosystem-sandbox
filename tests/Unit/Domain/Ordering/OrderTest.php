<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ordering;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Event\OrderPlaced;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Tests\Support\OrderMachine;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\RuleSequence;
use Rasuvaeff\Specification\SpecificationBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Order::class)]
#[Covers(OrderTransition::class)]
final class OrderTest
{
    /**
     * Model-based: random sequences of every transition, allowed or not,
     * against a model that is only the status (OrderMachine).
     */
    #[Property(runs: 200)]
    public function theLifecycleFollowsItsTransitionTable(RuleSequence $sequence): void
    {
        $sequence->run(factory: static fn (): OrderMachine => new OrderMachine());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theLifecycleFollowsItsTransitionTableGenerators(): array
    {
        return ['sequence' => Gen::rules(OrderMachine::class, minLength: 1, maxLength: 12)];
    }

    public function placingRecordsOrderPlacedOnce(): void
    {
        $order = Order::place(id: new OrderId(value: 'o-1'), customerId: new CustomerId(value: 'c-1'), amount: new Amount(cents: 500));

        $events = $order->releaseEvents();

        Assert::same(count($events), expected: 1);
        Assert::instanceOf($events[0], OrderPlaced::class);
        Assert::same($order->status(), OrderStatus::Pending);
        Assert::same($order->releaseEvents(), []);
    }

    public function restoringRecordsNothing(): void
    {
        $order = Order::restore(id: new OrderId(value: 'o-1'), customerId: new CustomerId(value: 'c-1'), amount: new Amount(cents: 500), status: OrderStatus::Paid);

        Assert::same($order->releaseEvents(), []);
    }

    /**
     * A replay of an order id must carry the same payload: any other customer
     * or amount is a different purchase.
     */
    #[Property(runs: 200)]
    public function anOrderIsPlacedAsExactlyItsOwnPayload(string $customer, int $cents, string $otherCustomer, int $otherCents): void
    {
        $order = Order::place(id: new OrderId(value: 'o-1'), customerId: new CustomerId(value: $customer), amount: new Amount(cents: $cents));
        Classify::cover($customer === $otherCustomer && $cents === $otherCents, label: 'same payload', minPercent: 10.0);
        Classify::cover($customer !== $otherCustomer || $cents !== $otherCents, label: 'different payload', minPercent: 10.0);

        Assert::same(
            $order->isPlacedAs(customerId: new CustomerId(value: $otherCustomer), amount: new Amount(cents: $otherCents)),
            $customer === $otherCustomer && $cents === $otherCents,
        );
        Assert::true($order->isPlacedAs(customerId: new CustomerId(value: $customer), amount: new Amount(cents: $cents)));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function anOrderIsPlacedAsExactlyItsOwnPayloadGenerators(): array
    {
        // A two-letter alphabet makes equal pairs common, so both answers occur.
        $customer = Gen::stringFrom(alphabet: 'ab', minLength: 1, maxLength: 1);
        $cents = Gen::intBetween(Amount::MIN_CENTS, max: 2);

        return ['customer' => $customer, 'cents' => $cents, 'otherCustomer' => $customer, 'otherCents' => $cents];
    }

    public function theTransitionTableIsTheWorkflowDefinition(): void
    {
        Assert::same(OrderTransition::definitions(), [
            ['name' => 'start_payment', 'from' => OrderStatus::Pending, 'to' => OrderStatus::Processing],
            ['name' => 'mark_paid', 'from' => OrderStatus::Processing, 'to' => OrderStatus::Paid],
            ['name' => 'mark_failed', 'from' => OrderStatus::Processing, 'to' => OrderStatus::PaymentFailed],
            ['name' => 'recover_payment', 'from' => OrderStatus::PaymentFailed, 'to' => OrderStatus::Paid],
        ]);
    }

    /** Events accumulate until released, all of them, in order. */
    public function everyEventRecordedSinceTheLastReleaseIsReleased(): void
    {
        $order = Order::place(id: new OrderId(value: 'o-1'), customerId: new CustomerId(value: 'c-1'), amount: new Amount(cents: 500));
        Assert::true($order->apply(transition: OrderTransition::StartPayment)->isOk());

        $events = $order->releaseEvents();

        Assert::same(array_map(static fn (object $event): string => $event::class, $events), [OrderPlaced::class, OrderStatusChanged::class]);
    }

    /**
     * A policy may name any column of the order: the in-memory snapshot
     * carries them all, with the names the read models query.
     */
    #[Property(runs: 100)]
    public function aPolicyOverAnyColumnSeesTheOrdersOwnValues(string $id, string $customer, int $cents): void
    {
        $order = Order::place(id: new OrderId(value: $id), customerId: new CustomerId(value: $customer), amount: new Amount(cents: $cents));
        $own = SpecificationBuilder::create()
            ->whereEqual(column: 'id', value: $id)
            ->whereEqual(column: 'customer_id', value: $customer)
            ->whereEqual(column: 'amount_cents', value: $cents)
            ->whereEqual(column: 'status', value: OrderStatus::Pending->value)
            ->build();

        Assert::true($order->satisfies(policy: $own));
        Assert::false($order->satisfies(policy: SpecificationBuilder::create()->whereGreaterThan(column: 'amount_cents', value: $cents)->build()));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aPolicyOverAnyColumnSeesTheOrdersOwnValuesGenerators(): array
    {
        return [
            'id' => Gen::stringOf(minLength: 1, maxLength: OrderId::MAX_LENGTH),
            'customer' => Gen::stringOf(minLength: 1, maxLength: CustomerId::MAX_LENGTH),
            'cents' => Gen::intBetween(Amount::MIN_CENTS, Amount::MAX_CENTS),
        ];
    }
}
