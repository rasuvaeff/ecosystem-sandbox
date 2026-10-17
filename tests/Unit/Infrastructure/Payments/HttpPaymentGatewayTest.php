<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payments;

use App\Infrastructure\Payments\Gateway\HttpPaymentGateway;
use App\Infrastructure\Payments\Gateway\PaymentRequestRejectedException;
use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(HttpPaymentGateway::class)]
final class HttpPaymentGatewayTest
{
    public function approvedResponseBecomesASucceededAttempt(): void
    {
        $gateway = $this->gateway(status: 200, payload: ['approved' => true, 'reference' => 'gw-1']);

        $attempt = $gateway->createPayment($this->request());

        Assert::same($attempt->state, PaymentState::Succeeded);
        Assert::same($attempt->payment->id, expected: 'gw-1');
        Assert::same($attempt->rawStatus, expected: 'approved');
        Assert::null($attempt->failure);
    }

    public function declinedResponseBecomesAFailedAttemptWithTheProviderCode(): void
    {
        $gateway = $this->gateway(status: 200, payload: ['approved' => false, 'error' => 'insufficient_funds']);

        $attempt = $gateway->createPayment($this->request());

        Assert::same($attempt->state, PaymentState::Failed);
        Assert::same($attempt->failure?->code, expected: 'insufficient_funds');
    }

    /**
     * A 5xx must leave the gateway as an exception: it is the signal the
     * resilience pipeline classifies as a failure (retry, then breaker),
     * while a decline is a final business answer that must not be retried.
     */
    public function serverErrorThrowsInsteadOfReturningAFinalState(): void
    {
        $gateway = $this->gateway(status: 503, payload: ['error' => 'unavailable']);

        try {
            $gateway->createPayment($this->request());
            Assert::fail(message: 'A 5xx gateway response must not be mapped to a payment state');
        } catch (\RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('503');
        }
    }

    /**
     * A proxy in front of the gateway answers 502/503 with HTML. Decoding it
     * first used to throw JsonException, which is not a RuntimeException, so
     * the circuit breaker never counted the outage.
     */
    public function aNonJsonServerErrorIsStillATransientFailure(): void
    {
        $gateway = $this->rawGateway(status: 502, body: '<html><body>Bad Gateway</body></html>');

        try {
            $gateway->createPayment($this->request());
            Assert::fail(message: 'A 502 with an HTML body must fail as a transient gateway error');
        } catch (\RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('502');
        }
    }

    /**
     * Throttling and timeouts are answers about the moment, not about the
     * payment: they must reach the retry and the breaker, never a decline.
     */
    #[DataProvider('transientClientErrorProvider')]
    public function aTransientClientErrorIsNotADecline(int $status): void
    {
        $gateway = $this->gateway($status, ['error' => 'try_later']);

        try {
            $gateway->createPayment($this->request());
            Assert::fail('HTTP ' . $status . ' must not be mapped to a payment state');
        } catch (\RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains((string) $status);
        }
    }

    public static function transientClientErrorProvider(): iterable
    {
        yield 'request timeout' => [408];
        yield 'conflict' => [409];
        yield 'too early' => [425];
        yield 'too many requests' => [429];
    }

    /**
     * A 422 means this application sent a request the gateway cannot take.
     * Retrying will not change that and the customer did not decline, so it
     * is neither a RuntimeException (retry, breaker) nor a payment state.
     */
    public function aRejectedRequestIsNeitherADeclineNorATransientFailure(): void
    {
        $gateway = $this->gateway(status: 422, payload: ['error' => 'orderId and Idempotency-Key are required']);

        try {
            $gateway->createPayment($this->request());
            Assert::fail(message: 'HTTP 422 must not be mapped to a payment state');
        } catch (PaymentRequestRejectedException $exception) {
            Assert::false($exception instanceof \RuntimeException);
            Assert::string($exception->getMessage())->contains('422');
        }
    }

    public function paymentRequiredIsADecline(): void
    {
        $gateway = $this->gateway(status: 402, payload: ['approved' => false, 'reference' => 'mock-declined']);

        $attempt = $gateway->createPayment($this->request());

        Assert::same($attempt->state, PaymentState::Failed);
        Assert::same($attempt->payment->id, expected: 'mock-declined');
    }

    public function theGatewayIdempotencyKeyIsSentWithTheCharge(): void
    {
        $captured = Arg::captor(RequestInterface::class);
        $client = Understudy::for(ClientInterface::class);
        when(static fn () => $client->sendRequest($captured->capture()))->answers(
            static fn (Invocation $call): Response => self::jsonResponse(status: 200, payload: ['approved' => true, 'reference' => 'gw-2']),
        );

        new HttpPaymentGateway($client, baseUrl: 'http://payment-mock:8081', clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')))
            ->createPayment($this->request());

        Assert::same($captured->last()->getHeaderLine('Idempotency-Key'), expected: 'payment-order-1');
    }

    private function gateway(int $status, array $payload): HttpPaymentGateway
    {
        $client = Understudy::for(ClientInterface::class);
        when(static fn () => $client->sendRequest(Arg::any()))->answers(
            static fn (Invocation $call): Response => self::jsonResponse($status, $payload),
        );

        return new HttpPaymentGateway($client, baseUrl: 'http://payment-mock:8081', clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')));
    }

    private function rawGateway(int $status, string $body): HttpPaymentGateway
    {
        $client = Understudy::for(ClientInterface::class);
        when(static fn () => $client->sendRequest(Arg::any()))->answers(
            static fn (Invocation $call): Response => new Response(
                $status,
                ['Content-Type' => 'text/html'],
                new StreamFactory()->createStream($body),
            ),
        );

        return new HttpPaymentGateway($client, baseUrl: 'http://payment-mock:8081', clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')));
    }

    private function request(): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: new OperationId(value: 'order-1'),
            amount: Money::minorUnits(amount: 1999, currency: 'USD'),
            description: 'Sandbox checkout order-1',
            metadata: ['orderId' => 'order-1'],
            idempotencyKey: 'payment-order-1',
        );
    }

    private static function jsonResponse(int $status, array $payload): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            new StreamFactory()->createStream(json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }
}
