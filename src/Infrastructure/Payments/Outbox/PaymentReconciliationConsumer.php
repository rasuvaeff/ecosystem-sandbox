<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Outbox;

use App\Application\Payments\Integration\PaymentReconciliationRequested;
use App\Application\Payments\ReconcilePayment\ReconcilePaymentCommand;
use App\Application\Payments\ReconcilePayment\ReconcilePaymentHandler;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;
use App\Infrastructure\Shared\Outbox\IntegrationMessages;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

/**
 * The reconciliation step of the payment saga, taken by the payment worker.
 * A bank that cannot be asked now is asked again by the outbox.
 */
final readonly class PaymentReconciliationConsumer implements PublisherInterface
{
    public function __construct(
        private IntegrationMessages $messages,
        private ReconcilePaymentHandler $reconcile,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $event = $this->messages->parse(message: $message, expected: PaymentReconciliationRequested::class);

        try {
            $this->reconcile->handle(command: new ReconcilePaymentCommand(orderId: new OrderId(value: $event->orderId)));
        } catch (ChargeRejectedException $exception) {
            throw PublishException::terminal(message: $exception->getMessage(), outboxMessage: $message, previous: $exception);
        } catch (GatewayUnavailableException $exception) {
            throw new PublishException(message: $exception->getMessage(), outboxMessage: $message, previous: $exception);
        }
    }
}
