<?php

declare(strict_types=1);

namespace App\Application\Payments\CapturePayment;

use App\Domain\Ordering\OrderId;

final readonly class CapturePaymentCommand
{
    /**
     * @param non-empty-string $gatewayIdempotencyKey stable per order: the gateway charges once whatever the retries
     * @param non-empty-string $attemptKey identifies this delivery of the request (the outbox message)
     * @param bool $lastAttempt whether the outbox will deliver the request again if this attempt fails
     */
    public function __construct(
        public OrderId $orderId,
        public string $gatewayIdempotencyKey,
        public string $attemptKey,
        public bool $lastAttempt,
    ) {}
}
