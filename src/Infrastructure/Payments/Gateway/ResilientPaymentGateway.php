<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateway;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Charge;
use App\Domain\Payments\ChargeFinding;
use App\Domain\Payments\ChargeResult;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Domain\Payments\PaymentGateway;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Resilience\CompiledPipeline;

/**
 * The domain's gateway on rasuvaeff/payments behind the resilience pipeline
 * (bulkhead, retry, circuit breaker) and one deadline (rasuvaeff/context).
 */
final readonly class ResilientPaymentGateway implements PaymentGateway
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private CompiledPipeline $resilience,
        /**
         * Retries without the charge's circuit breaker: the outcome a
         * reconciliation looks for is usually lost to exactly the failures
         * that opened it, and waiting out its cooldown would stall every
         * reconciliation behind it.
         */
        private CompiledPipeline $reconciliation,
        private float $deadlineSeconds,
    ) {}

    public function charge(Charge $charge): ChargeResult
    {
        [$context] = Context::background()->withTimeout(seconds: $this->deadlineSeconds);

        try {
            $attempt = $this->resilience->call(
                // The pipeline holds the deadline: Retry checks it before each
                // attempt, outside the breaker, so a budget that ran out stops
                // the retries without being counted as gateway failures. PSR-18
                // has no per-request timeout, so one attempt is bounded by the
                // HTTP client's own (`payment.gateway_timeout_ms`), and an
                // answer that arrives late is still honoured: an approved
                // charge is real money whether or not the budget ran out.
                callback: fn (): PaymentAttempt => $this->gateway->createPayment(new CreatePaymentRequest(
                    operationId: new OperationId(value: $charge->orderId->value),
                    amount: Money::minorUnits(amount: $charge->amount->cents, currency: Amount::CURRENCY),
                    description: 'Sandbox checkout ' . $charge->orderId->value,
                    metadata: ['orderId' => $charge->orderId->value],
                    idempotencyKey: $charge->idempotencyKey,
                )),
                context: $context,
            );
        } catch (PaymentRequestRejectedException $exception) {
            throw new ChargeRejectedException(message: 'Payment gateway rejected the request', previous: $exception);
        } catch (\Throwable $exception) {
            throw new GatewayUnavailableException(message: 'Payment gateway is temporarily unavailable', previous: $exception);
        }

        return $attempt->state === PaymentState::Succeeded ? ChargeResult::Approved : ChargeResult::Declined;
    }

    public function find(OrderId $orderId, string $gatewayKey): ChargeFinding
    {
        [$context] = Context::background()->withTimeout(seconds: $this->deadlineSeconds);

        try {
            $attempt = $this->reconciliation->call(
                callback: fn (): PaymentAttempt => $this->gateway->retrievePayment(new RetrievePaymentRequest(
                    operationId: new OperationId(value: $orderId->value),
                    payment: new PaymentReference(provider: $this->gateway->provider(), id: $gatewayKey),
                )),
                context: $context,
            );
        } catch (\Throwable $exception) {
            throw new GatewayUnavailableException(message: 'Payment gateway could not be reconciled', previous: $exception);
        }

        return $attempt->state === PaymentState::Succeeded ? ChargeFinding::Charged : ChargeFinding::NotCharged;
    }
}
