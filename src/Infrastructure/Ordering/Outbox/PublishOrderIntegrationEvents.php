<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Outbox;

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Application\Shared\Integration\IntegrationEventBus;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Ordering\PaymentIdempotencyKey;
use App\Infrastructure\Shared\Correlation\CurrentRequestId;
use Rasuvaeff\Yii3Telemetry\QueueTracing;

/**
 * Ordering's integration events. A status change is written to the outbox in
 * the transaction that made it, under the frozen types the consumers know:
 * `payment.requested` (the worker), `order.paid` and `order.payment_failed`
 * (the result subscribers).
 */
final readonly class PublishOrderIntegrationEvents
{
    public function __construct(
        private IntegrationEventBus $bus,
        private QueueTracing $queueTracing,
        private CurrentRequestId $requestId,
    ) {}

    public function __invoke(OrderStatusChanged $event): void
    {
        $orderId = $event->orderId->value;
        $this->bus->publish(event: match ($event->transition) {
            OrderTransition::StartPayment => new PaymentRequested(
                orderId: $orderId,
                amountCents: $event->amount->cents,
                gatewayIdempotencyKey: PaymentIdempotencyKey::forOrder(orderId: $event->orderId),
                // Carries the HTTP correlation ID into the worker: the
                // middleware scope ends with the 202 response.
                correlationId: $this->requestId->get(),
                // W3C trace context of the checkout request: the worker
                // continues this trace, so one trace shows the HTTP half and
                // the payment half together.
                trace: $this->queueTracing->inject(metadata: []),
            ),
            // A recovered payment tells the subscribers again: the order they
            // heard had failed is paid.
            OrderTransition::MarkPaid, OrderTransition::RecoverPayment => new OrderPaid(orderId: $orderId),
            OrderTransition::MarkFailed => new OrderPaymentFailed(orderId: $orderId),
        });
    }
}
