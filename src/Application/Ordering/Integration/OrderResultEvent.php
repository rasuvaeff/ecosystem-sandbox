<?php

declare(strict_types=1);

namespace App\Application\Ordering\Integration;

use App\Application\Shared\Integration\IntegrationEvent;

/**
 * How an order's payment ended. The result subscribers (realtime, webhooks,
 * receipts, ClickHouse) take both and tell them apart by status().
 */
interface OrderResultEvent extends IntegrationEvent
{
    /**
     * @return non-empty-string
     */
    public function orderId(): string;

    /**
     * @return 'paid'|'payment_failed' the status the order now has
     */
    public function status(): string;
}
