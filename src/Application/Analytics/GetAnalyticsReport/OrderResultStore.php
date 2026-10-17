<?php

declare(strict_types=1);

namespace App\Application\Analytics\GetAnalyticsReport;

use App\Domain\Analytics\OrderSource;

/**
 * Payment results as the analytics store holds them, deduplicated (one row
 * per outbox event whatever the redeliveries).
 */
interface OrderResultStore
{
    public function availability(): ResultStoreAvailability;

    /**
     * @return list<array{status: string, orders: int, amountCents: int}>
     */
    public function totalsByStatus(OrderSource $source): array;

    /**
     * @param positive-int $limit
     *
     * @return list<array{orderId: string, status: string, amountCents: int, occurredAt: string}>
     */
    public function latest(OrderSource $source, int $limit): array;

    /**
     * @return array{orders: int, amountCents: int}
     */
    public function summary(OrderSource $source): array;
}
