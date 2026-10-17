<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Application\Payments\Integration\PaymentReconciliationRequested;
use App\Application\Shared\Integration\IntegrationEventRegistry;
use App\Infrastructure\Shared\Outbox\IntegrationMessages;

/**
 * The application's integration events, registered as SharedBootloader
 * registers them.
 */
final class IntegrationEvents
{
    public static function registry(): IntegrationEventRegistry
    {
        return new IntegrationEventRegistry(PaymentRequested::class, OrderPaid::class, OrderPaymentFailed::class, PaymentReconciliationRequested::class);
    }

    public static function messages(): IntegrationMessages
    {
        return new IntegrationMessages(registry: self::registry());
    }
}
