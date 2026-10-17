<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateway;

/**
 * The gateway refused the request itself (400, 401, 403, 404, 422, ...): the
 * application sent something the gateway cannot take. It is deliberately not a
 * RuntimeException — retrying the same request cannot succeed, and an outage
 * signal for the circuit breaker it is not. Nor is it a decline: the customer
 * never got an answer about the payment.
 */
final class PaymentRequestRejectedException extends \LogicException {}
