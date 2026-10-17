<?php

declare(strict_types=1);

namespace App\Bootloader;

use App\Application\Operations\GetCheckoutTrace\CheckoutTraceLocator;
use App\Application\Operations\GetCheckoutTrace\TraceStore;
use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Application\Payments\Integration\PaymentReconciliationRequested;
use App\Application\Shared\Inbox\Inbox;
use App\Application\Shared\Integration\IntegrationEventBus;
use App\Application\Shared\Integration\IntegrationEventRegistry;
use App\Domain\Shared\Transaction\TransactionManager;
use App\Infrastructure\Operations\Tracing\JaegerTraceStore;
use App\Infrastructure\Operations\Tracing\OutboxCheckoutTraceLocator;
use App\Infrastructure\Shared\Inbox\DbInbox;
use App\Infrastructure\Shared\Outbox\OutboxIntegrationEventBus;
use App\Infrastructure\Shared\Transaction\DbTransactionManager;
use Yiisoft\Di\ServiceProviderInterface;

final readonly class SharedBootloader implements ServiceProviderInterface
{
    public function getDefinitions(): array
    {
        return [
            TransactionManager::class => DbTransactionManager::class,
            IntegrationEventBus::class => OutboxIntegrationEventBus::class,
            Inbox::class => DbInbox::class,
            // Every integration event the application exchanges; consumers
            // parse only through here.
            IntegrationEventRegistry::class => static fn (): IntegrationEventRegistry => new IntegrationEventRegistry(
                PaymentRequested::class,
                OrderPaid::class,
                OrderPaymentFailed::class,
                PaymentReconciliationRequested::class,
            ),
            CheckoutTraceLocator::class => OutboxCheckoutTraceLocator::class,
            TraceStore::class => JaegerTraceStore::class,
        ];
    }

    public function getExtensions(): array
    {
        return [];
    }
}
