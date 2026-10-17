<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderEvidence;

use App\Domain\Ordering\OrderId;

/**
 * What the database holds about an order beyond the row itself: the audit
 * rows and the outbox messages it caused, without their payloads.
 */
interface OrderActivityReader
{
    /**
     * @return list<AuditEntry> by time, oldest first
     */
    public function audit(OrderId $orderId): array;

    /**
     * @return list<OutboxEntry> by time, oldest first
     */
    public function outbox(OrderId $orderId): array;

    /**
     * How many times the outbox delivers a message before giving up.
     */
    public function maxAttempts(): int;
}
