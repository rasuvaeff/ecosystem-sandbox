<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Outbox;

use App\Application\Ordering\Integration\PaymentRequested;
use App\Application\Payments\CapturePayment\CapturePaymentCommand;
use App\Application\Payments\CapturePayment\CapturePaymentHandler;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Infrastructure\Shared\Outbox\IntegrationMessages;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Telemetry\QueueTracing;

/**
 * The payment worker's door: one `payment.requested` outbox message becomes
 * one CapturePayment command, inside the checkout's trace and correlation
 * scope. What the outbox should do next is decided here, from the domain's
 * answer: retry an unavailable gateway, give up a rejected or unknown order.
 */
final readonly class PaymentRequestedConsumer implements PublisherInterface
{
    public function __construct(
        private CapturePaymentHandler $capture,
        private IntegrationMessages $messages,
        private CorrelationIdHolder $correlation,
        private RetryPolicy $retryPolicy,
        private QueueTracing $queueTracing,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $event = $this->messages->parse(message: $message, expected: PaymentRequested::class);
        // One CONSUMER span per message, parented by the checkout request's
        // trace context: the HTTP half and the payment half land in one trace.
        $this->queueTracing->consume(
            metadata: $event->trace,
            name: 'process ' . $message->getType(),
            handler: fn (): null => $this->correlation->runWith(
                // Re-opens the HTTP request's correlation scope, so audit rows
                // and log records of both halves of the checkout share one id.
                // The id passes the same format contract the middleware
                // applies before it opens a scope: a payload is untrusted.
                id: $event->correlationId !== null && preg_match(pattern: CorrelationIdMiddleware::UUID_V4_PATTERN, subject: $event->correlationId) === 1
                    ? $event->correlationId
                    : 'outbox-' . $message->getId(),
                callback: fn (): null => $this->capture(message: $message, event: $event),
            ),
            attributes: ['messaging.system' => 'postgres-outbox', 'messaging.destination.name' => 'outbox', 'messaging.message.id' => $message->getId()],
        );
    }

    private function capture(OutboxMessage $message, PaymentRequested $event): null
    {
        try {
            $this->capture->handle(command: new CapturePaymentCommand(
                orderId: new OrderId(value: $event->orderId),
                gatewayIdempotencyKey: $event->gatewayIdempotencyKey,
                attemptKey: 'payment-transition-' . $message->getId(),
                // The Processor counted this attempt before calling publish().
                lastAttempt: !$this->retryPolicy->shouldRetry(message: $message),
            ));
        } catch (OrderNotFoundException) {
            throw PublishException::terminal(message: 'Payment order does not exist', outboxMessage: $message);
        } catch (ChargeRejectedException $exception) {
            throw PublishException::terminal(message: $exception->getMessage(), outboxMessage: $message, previous: $exception);
        } catch (GatewayUnavailableException $exception) {
            throw new PublishException(message: $exception->getMessage(), outboxMessage: $message, previous: $exception);
        }

        return null;
    }
}
