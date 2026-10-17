<?php

declare(strict_types=1);

use App\Application\Marketing\Event\CreditOrderToMarketing;
use App\Application\Ordering\Event\ApplyPaymentOutcome;
use App\Domain\Ordering\Event\OrderPlaced;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Payments\Event\PaymentCaptured;
use App\Domain\Payments\Event\PaymentDeclined;
use App\Infrastructure\Ordering\Audit\AuditOrderStatusChanges;
use App\Infrastructure\Ordering\Outbox\PublishOrderIntegrationEvents;

// Domain events and who reacts to them, in-process and inside the transaction
// that raised them.
return [
    // The projection first: Marketing's listener credits the campaign and
    // dispatches CheckoutAttributed, which updates the row placing made.
    OrderPlaced::class => [
        [App\Infrastructure\Analytics\Projection\OrderSummaryProjector::class, 'onPlaced'],
        CreditOrderToMarketing::class,
    ],
    OrderStatusChanged::class => [
        PublishOrderIntegrationEvents::class,
        AuditOrderStatusChanges::class,
        [App\Infrastructure\Analytics\Projection\OrderSummaryProjector::class, 'onStatusChanged'],
    ],
    App\Domain\Marketing\Event\CheckoutAttributed::class => [[App\Infrastructure\Analytics\Projection\OrderSummaryProjector::class, 'onAttributed']],
    PaymentCaptured::class => [[ApplyPaymentOutcome::class, 'onCaptured']],
    PaymentDeclined::class => [[ApplyPaymentOutcome::class, 'onDeclined']],
    App\Domain\Payments\Event\PaymentRecovered::class => [[ApplyPaymentOutcome::class, 'onRecovered']],
];
