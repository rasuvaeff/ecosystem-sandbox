<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ordering;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\PaymentIdempotencyKey;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The domain's bounds are the only statement of what an order may hold: the
 * checkout schema and the payments package both take them from here.
 */
#[Test]
#[Covers(Amount::class)]
#[Covers(OrderId::class)]
#[Covers(CustomerId::class)]
#[Covers(PaymentIdempotencyKey::class)]
final class ValueObjectsTest
{
    #[Property(runs: 300)]
    public function anAmountExistsExactlyInsideItsBounds(int $cents): void
    {
        $inside = $cents >= Amount::MIN_CENTS && $cents <= Amount::MAX_CENTS;
        Classify::cover($inside, label: 'inside', minPercent: 20.0);
        Classify::cover(!$inside, label: 'outside', minPercent: 20.0);

        try {
            Assert::same(new Amount(cents: $cents)->cents, $cents);
            Assert::true($inside);
        } catch (\InvalidArgumentException) {
            Assert::false($inside);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function anAmountExistsExactlyInsideItsBoundsGenerators(): array
    {
        return ['cents' => Gen::frequency([
            [2, Gen::intBetween(Amount::MIN_CENTS, Amount::MAX_CENTS)],
            [1, Gen::intBetween(PHP_INT_MIN, Amount::MIN_CENTS - 1)],
            [1, Gen::intBetween(Amount::MAX_CENTS + 1, PHP_INT_MAX)],
        ])];
    }

    /** @return iterable<string, array{int}> */
    public static function anAmountExistsExactlyInsideItsBoundsExamples(): iterable
    {
        yield 'zero' => [0];
        yield 'minimum' => [Amount::MIN_CENTS];
        yield 'maximum' => [Amount::MAX_CENTS];
        yield 'one above the maximum' => [Amount::MAX_CENTS + 1];
    }

    /**
     * tryFrom() is the constructor's verdict without the exception: an id
     * from a URL either is an order id or names no order.
     */
    #[Property(runs: 300)]
    public function tryFromAgreesWithTheConstructor(string $value): void
    {
        $tried = OrderId::tryFrom(value: $value);
        Classify::cover($tried instanceof OrderId, label: 'an id', minPercent: 20.0);
        Classify::cover(!$tried instanceof OrderId, label: 'not an id', minPercent: 10.0);

        try {
            $built = new OrderId(value: $value);
            Assert::same($tried?->value, $built->value);
        } catch (\InvalidArgumentException) {
            Assert::null($tried);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function tryFromAgreesWithTheConstructorGenerators(): array
    {
        return ['value' => Gen::frequency([
            [3, Gen::stringOf(minLength: 1, maxLength: OrderId::MAX_LENGTH)],
            [1, Gen::stringOf(minLength: OrderId::MAX_LENGTH + 1, maxLength: OrderId::MAX_LENGTH + 8)],
        ])];
    }

    /** @return iterable<string, array{string}> */
    public static function tryFromAgreesWithTheConstructorExamples(): iterable
    {
        yield 'empty' => [''];
        yield 'longest, multibyte' => [str_repeat(string: 'я', times: OrderId::MAX_LENGTH)];
        yield 'one too long, multibyte' => [str_repeat(string: 'я', times: OrderId::MAX_LENGTH + 1)];
    }

    /**
     * Every order id the domain admits yields a key the payments package
     * accepts, and the same order always the same key: a redelivered charge
     * has to look identical to the gateway. Found by the UI, where generated
     * orders with spaces in their ids were never paid.
     */
    #[Property(runs: 300)]
    public function everyAdmittedOrderYieldsOneStableAcceptedKey(string $orderId): void
    {
        $key = PaymentIdempotencyKey::forOrder(orderId: new OrderId(value: $orderId));

        $request = new CreatePaymentRequest(
            operationId: new OperationId(value: 'order'),
            amount: Money::minorUnits(amount: 100, currency: Amount::CURRENCY),
            description: 'checkout',
            metadata: ['orderId' => $orderId],
            idempotencyKey: $key,
        );

        Assert::same($request->idempotencyKey, $key);
        Assert::same(PaymentIdempotencyKey::forOrder(orderId: new OrderId(value: $orderId)), $key);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyAdmittedOrderYieldsOneStableAcceptedKeyGenerators(): array
    {
        return ['orderId' => Gen::stringOf(minLength: 1, maxLength: OrderId::MAX_LENGTH)];
    }

    /** @return iterable<string, array{string}> */
    public static function everyAdmittedOrderYieldsOneStableAcceptedKeyExamples(): iterable
    {
        yield 'space' => ['order 1'];
        yield 'control character' => ["order\t1"];
        yield 'unicode' => ['заказ ✓'];
        yield 'longest' => [str_repeat(string: 'я', times: OrderId::MAX_LENGTH)];
    }

    #[Property(runs: 200)]
    public function differentOrdersGetDifferentKeys(string $a, string $b): void
    {
        Assert::same(
            PaymentIdempotencyKey::forOrder(orderId: new OrderId(value: $a)) === PaymentIdempotencyKey::forOrder(orderId: new OrderId(value: $b)),
            $a === $b,
        );
    }

    /** @return array<string, ArbitraryInterface> */
    public static function differentOrdersGetDifferentKeysGenerators(): array
    {
        $id = Gen::stringFrom(alphabet: 'xy', minLength: 1, maxLength: 3);

        return ['a' => $id, 'b' => $id];
    }

    /** The refusals name the bounds: they reach a client as a problem detail. */
    public function theBoundsAreNamedInTheRefusal(): void
    {
        foreach ([
            static fn (): Amount => new Amount(cents: 0),
            static fn (): OrderId => new OrderId(value: ''),
            static fn (): CustomerId => new CustomerId(value: ''),
        ] as $index => $build) {
            try {
                $build();
                Assert::fail(message: 'An out-of-bounds value must be refused');
            } catch (\InvalidArgumentException $refused) {
                Assert::same($refused->getMessage(), ['An amount is 1 to 100000000 cents', 'An order id is 1 to 64 characters', 'A customer id is 1 to 64 characters'][$index]);
            }
        }
    }

    /** The gateway and the refund key are built on this exact shape. */
    #[Property(runs: 100)]
    public function theKeyIsPaymentDashSha256OfTheOrderId(string $orderId): void
    {
        Assert::same(PaymentIdempotencyKey::forOrder(orderId: new OrderId(value: $orderId)), 'payment-' . hash(algo: 'sha256', data: $orderId));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theKeyIsPaymentDashSha256OfTheOrderIdGenerators(): array
    {
        return ['orderId' => Gen::stringOf(minLength: 1, maxLength: OrderId::MAX_LENGTH)];
    }

    /** A customer id is 1 to 64 characters, counted as characters, not bytes. */
    #[Property(runs: 300)]
    public function aCustomerIdExistsExactlyInsideItsBounds(string $value): void
    {
        $inside = $value !== '' && mb_strlen(string: $value) <= CustomerId::MAX_LENGTH;
        Classify::cover($inside, label: 'inside', minPercent: 20.0);
        Classify::cover(!$inside, label: 'outside', minPercent: 10.0);

        try {
            Assert::same(new CustomerId(value: $value)->value, $value);
            Assert::true($inside);
        } catch (\InvalidArgumentException) {
            Assert::false($inside);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aCustomerIdExistsExactlyInsideItsBoundsGenerators(): array
    {
        return ['value' => Gen::frequency([
            [3, Gen::stringOf(minLength: 1, maxLength: CustomerId::MAX_LENGTH)],
            [1, Gen::stringOf(minLength: CustomerId::MAX_LENGTH + 1, maxLength: CustomerId::MAX_LENGTH + 8)],
        ])];
    }

    /** @return iterable<string, array{string}> */
    public static function aCustomerIdExistsExactlyInsideItsBoundsExamples(): iterable
    {
        yield 'empty' => [''];
        yield 'longest, multibyte' => [str_repeat(string: 'я', times: CustomerId::MAX_LENGTH)];
        yield 'one too long, multibyte' => [str_repeat(string: 'я', times: CustomerId::MAX_LENGTH + 1)];
        yield 'exactly the limit' => [str_repeat(string: 'c', times: CustomerId::MAX_LENGTH)];
    }
}
