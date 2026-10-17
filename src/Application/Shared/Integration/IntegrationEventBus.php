<?php

declare(strict_types=1);

namespace App\Application\Shared\Integration;

/**
 * Publishes an integration event. The outbox implementation records it in
 * the caller's transaction, so the event exists exactly when the state
 * change that caused it does.
 */
interface IntegrationEventBus
{
    public function publish(IntegrationEvent $event): void;
}
