<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payments;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Charge;
use App\Domain\Payments\ChargeFinding;
use App\Domain\Payments\ChargeResult;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Infrastructure\Payments\Gateway\PaymentRequestRejectedException;
use App\Infrastructure\Payments\Gateway\ResilientPaymentGateway;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentFailure;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

/**
 * The domain's gateway on rasuvaeff/payments: the package's states and
 * failures become the domain's two results and two exceptions.
 */
#[Test]
#[Covers(ResilientPaymentGateway::class)]
final class ResilientPaymentGatewayTest
{
    #[DataProvider('stateProvider')]
    public function aPaymentStateBecomesADomainResult(PaymentState $state, ChargeResult $result): void
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(static fn () => $gateway->createPayment(Arg::any()))->returns(self::attempt(state: $state));

        Assert::same($this->gateway(gateway: $gateway)->charge(charge: self::charge()), $result);
    }

    public static function stateProvider(): iterable
    {
        yield 'succeeded is approved' => [PaymentState::Succeeded, ChargeResult::Approved];
        yield 'failed is declined' => [PaymentState::Failed, ChargeResult::Declined];
    }

    #[DataProvider('failureProvider')]
    public function aFailureBecomesADomainException(\Throwable $thrown, string $expected): void
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(static fn () => $gateway->createPayment(Arg::any()))->throws($thrown);

        try {
            $this->gateway(gateway: $gateway)->charge(charge: self::charge());
            Assert::fail(message: 'A failed charge must not look like a result');
        } catch (ChargeRejectedException|GatewayUnavailableException $exception) {
            Assert::instanceOf($exception, $expected);
            Assert::same($exception->getPrevious(), $thrown);
        }
    }

    public static function failureProvider(): iterable
    {
        yield 'a refused request is rejected' => [new PaymentRequestRejectedException(message: 'HTTP 422'), ChargeRejectedException::class];
        yield 'anything else is unavailable' => [new \RuntimeException(message: 'connection reset'), GatewayUnavailableException::class];
    }

    public function theRequestCarriesTheOrderAndTheStableKey(): void
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        $request = Arg::captor(CreatePaymentRequest::class);
        when(static fn () => $gateway->createPayment($request->capture()))->returns(self::attempt(state: PaymentState::Succeeded));

        $this->gateway(gateway: $gateway)->charge(charge: self::charge());

        Assert::same($request->last()->operationId->value, expected: 'order-1');
        Assert::same($request->last()->amount->minorUnits, expected: 1999);
        Assert::same($request->last()->idempotencyKey, expected: 'payment-order-1');
    }

    /**
     * The deadline guards starting work, not the answer: a charge the gateway
     * already approved is real money and pays the order even if it arrived
     * after the budget ran out.
     */
    public function anApprovalThatArrivesAfterTheDeadlineStillCounts(): void
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(static fn () => $gateway->createPayment(Arg::any()))->answers(static function (): PaymentAttempt {
            usleep(microseconds: 50_000);

            return self::attempt(state: PaymentState::Succeeded);
        });

        Assert::same($this->gateway(gateway: $gateway, deadlineSeconds: 0.01)->charge(charge: self::charge()), ChargeResult::Approved);
    }

    public function aChargeTheBankHoldsIsFoundUnderItsKey(): void
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(static fn () => $gateway->provider())->returns(new PaymentProvider(value: 'sandbox'));
        $retrieve = Arg::captor(RetrievePaymentRequest::class);
        when(static fn () => $gateway->retrievePayment($retrieve->capture()))->returns(self::attempt(state: PaymentState::Succeeded));

        Assert::same($this->gateway(gateway: $gateway)->find(orderId: new OrderId(value: 'order-1'), gatewayKey: 'payment-order-1'), ChargeFinding::Charged);
        Assert::same($retrieve->last()->payment->id, expected: 'payment-order-1');
    }

    public function noChargeIsNotCharged(): void
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(static fn () => $gateway->provider())->returns(new PaymentProvider(value: 'sandbox'));
        when(static fn () => $gateway->retrievePayment(Arg::any()))->returns(self::attempt(state: PaymentState::Failed));

        Assert::same($this->gateway(gateway: $gateway)->find(orderId: new OrderId(value: 'order-1'), gatewayKey: 'payment-order-1'), ChargeFinding::NotCharged);
    }

    private function gateway(PaymentGatewayInterface $gateway, float $deadlineSeconds = 3.0): ResilientPaymentGateway
    {
        return new ResilientPaymentGateway(
            gateway: $gateway,
            resilience: Pipeline::for(name: 'payment-gateway-test')->build(),
            reconciliation: Pipeline::for(name: 'payment-reconciliation-test')->build(),
            deadlineSeconds: $deadlineSeconds,
        );
    }

    private static function charge(): Charge
    {
        return new Charge(orderId: new OrderId(value: 'order-1'), amount: new Amount(cents: 1999), idempotencyKey: 'payment-order-1');
    }

    private static function attempt(PaymentState $state): PaymentAttempt
    {
        $now = new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'))->now();

        return new PaymentAttempt(
            operationId: new OperationId(value: 'order-1'),
            provider: new PaymentProvider(value: 'sandbox'),
            payment: new PaymentReference(provider: new PaymentProvider(value: 'sandbox'), id: 'gw-1'),
            amount: Money::minorUnits(amount: 1999, currency: Amount::CURRENCY),
            state: $state,
            rawStatus: $state === PaymentState::Succeeded ? 'approved' : 'declined',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo(receivedAt: $now),
            failure: $state === PaymentState::Succeeded ? null : new PaymentFailure(code: 'payment_declined', message: 'Declined'),
        );
    }
}
