<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Outbox;

use App\Application\Shared\Integration\IntegrationEvent;
use App\Application\Shared\Integration\IntegrationEventBus;
use Rasuvaeff\Yii3Outbox\Outbox;

/**
 * Integration events as outbox rows (yii3-outbox), written on the caller's
 * connection: inside the transaction that changed the state, the row exists
 * exactly when the change does.
 */
final readonly class OutboxIntegrationEventBus implements IntegrationEventBus
{
    public function __construct(private Outbox $outbox) {}

    public function publish(IntegrationEvent $event): void
    {
        $this->outbox->record(
            type: $event::type(),
            payload: json_encode(value: $event->toPayload(), flags: JSON_THROW_ON_ERROR),
            aggregateId: $event->aggregateId(),
        );
    }
}
