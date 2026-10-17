<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateway;

use GuzzleHttp\Psr7\Request;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentFailure;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\SandboxCapability;
use Rasuvaeff\Schema\Schema;

final readonly class HttpPaymentGateway implements PaymentGatewayInterface
{
    private const int PAYMENT_REQUIRED = 402;

    /** Client errors that describe the moment, not the payment. */
    private const array TRANSIENT_CLIENT_ERRORS = [408, 409, 425, 429];

    public function __construct(
        private ClientInterface $client,
        /** the bank's base URL: /charge and /payments/{key} live under it */
        private string $baseUrl,
        private ClockInterface $clock,
    ) {}

    public function provider(): PaymentProvider
    {
        return new PaymentProvider(value: 'sandbox');
    }

    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of(new SandboxCapability());
    }

    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        $httpRequest = new Request(
            method: 'POST',
            uri: $this->baseUrl . '/charge',
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $request->idempotencyKey ?? (string) $request->operationId,
            ],
            body: json_encode([
                'orderId' => (string) ($request->metadata['orderId'] ?? $request->operationId),
                'amountCents' => $request->amount->minorUnits,
                'currency' => $request->amount->currency,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->sendRequest($httpRequest);

        $status = $response->getStatusCode();

        // The status decides before the body is read: an outage answered by a
        // proxy has an HTML body, and it must still reach the retry and the
        // circuit breaker as a RuntimeException.
        if ($status >= 500 || in_array($status, self::TRANSIENT_CLIENT_ERRORS, strict: true)) {
            throw new \RuntimeException('Payment gateway returned HTTP ' . $status);
        }

        if ($status >= 400 && $status !== self::PAYMENT_REQUIRED) {
            throw new PaymentRequestRejectedException('Payment gateway rejected the request with HTTP ' . $status);
        }

        if ($status < 200 || ($status >= 300 && $status < 400)) {
            throw new \RuntimeException('Payment gateway returned an invalid response: HTTP ' . $status);
        }

        try {
            $payload = json_decode((string) $response->getBody(), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Payment gateway returned invalid JSON with HTTP ' . $status, previous: $exception);
        }

        if (!is_array($payload)) {
            throw new \RuntimeException(message: 'Payment gateway returned an invalid JSON object');
        }

        $now = $this->clock->now();
        $approved = $status !== self::PAYMENT_REQUIRED && (bool) ($payload['approved'] ?? false);

        return new PaymentAttempt(
            operationId: $request->operationId,
            provider: $this->provider(),
            payment: new PaymentReference(
                provider: $this->provider(),
                id: (string) ($payload['reference'] ?? 'gateway-unknown'),
            ),
            amount: $request->amount,
            state: $approved ? PaymentState::Succeeded : PaymentState::Failed,
            rawStatus: $approved ? 'approved' : 'declined',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo(receivedAt: $now),
            failure: $approved ? null : new PaymentFailure(
                code: (string) ($payload['error'] ?? 'payment_declined'),
                message: 'Payment was declined by the sandbox gateway',
            ),
        );
    }

    /**
     * The charge made under the key in the reference: the sandbox bank looks
     * charges up by their idempotency key, which is all a caller whose reply
     * was lost has.
     */
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        $parsed = Schema::object([
            'state' => Schema::string(),
            'amountCents' => Schema::int()->min(value: 0),
            'reference' => Schema::string()->min(length: 1)->max(length: 255),
        ])->strip()->parse(input: $this->decoded(response: $this->client->sendRequest(new Request(
            method: 'GET',
            uri: $this->baseUrl . '/payments/' . rawurlencode($request->payment->id),
            headers: ['Accept' => 'application/json'],
        ))));
        if (!$parsed->ok) {
            throw new \RuntimeException(message: 'Payment gateway returned an unreadable payment');
        }
        /** @var array{state: string, amountCents: int<0, max>, reference: non-empty-string} $payment */
        $payment = $parsed->value;
        $now = $this->clock->now();
        $charged = $payment['state'] === 'succeeded';

        return new PaymentAttempt(
            operationId: $request->operationId,
            provider: $this->provider(),
            payment: new PaymentReference(provider: $this->provider(), id: $payment['reference']),
            amount: Money::minorUnits(amount: max(1, $payment['amountCents']), currency: 'USD'),
            state: $charged ? PaymentState::Succeeded : PaymentState::Failed,
            rawStatus: $charged ? 'succeeded' : 'not_found',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo(receivedAt: $now),
            failure: $charged ? null : new PaymentFailure(code: 'not_found', message: 'The bank holds no charge under this key'),
        );
    }

    /**
     * The status decides before the body is read, as for a charge: a 5xx or
     * a transient 4xx is the bank's moment, any other 4xx is a refusal. The
     * body is untrusted and goes to a schema as decoded.
     */
    private function decoded(ResponseInterface $response): mixed
    {
        $status = $response->getStatusCode();
        if ($status >= 500 || in_array($status, self::TRANSIENT_CLIENT_ERRORS, strict: true)) {
            throw new \RuntimeException('Payment gateway returned HTTP ' . $status);
        }
        if ($status >= 400) {
            throw new PaymentRequestRejectedException('Payment gateway rejected the request with HTTP ' . $status);
        }

        try {
            return json_decode((string) $response->getBody(), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Payment gateway returned invalid JSON with HTTP ' . $status, previous: $exception);
        }
    }
}
