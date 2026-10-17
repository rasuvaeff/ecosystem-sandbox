<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\Projection;

use App\Domain\Analytics\OrderSource;
use App\Domain\Marketing\Event\CheckoutAttributed;
use App\Domain\Ordering\Event\OrderPlaced;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Shared\TableNames;
use Psr\Clock\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Analytics' read model of orders, kept by the domain events of Ordering and
 * Marketing as they are dispatched — inside the transaction that placed or
 * moved the order, so a report read right after a checkout already sees it.
 * The reports query this table alone, never Ordering's or a package's.
 *
 * Built going forward: there is no event store to replay, so a database made
 * before the projection existed starts it empty.
 */
final readonly class OrderSummaryProjector
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
    ) {}

    public function onPlaced(OrderPlaced $event): void
    {
        $now = $this->clock->now()->format(format: self::TIMESTAMP);
        $this->db->createCommand()->upsert(
            table: TableNames::ORDER_SUMMARY,
            insertColumns: [
                'order_id' => $event->orderId->value,
                'customer_id' => $event->customerId->value,
                'source' => OrderSource::ofCustomer(customerId: $event->customerId->value)->value,
                'amount_cents' => $event->amount->cents,
                'status' => OrderStatus::Pending->value,
                'placed_at' => $now,
                'updated_at' => $now,
            ],
            updateColumns: false,
        )->execute();
    }

    public function onStatusChanged(OrderStatusChanged $event): void
    {
        $this->db->createCommand()->update(
            table: TableNames::ORDER_SUMMARY,
            columns: ['status' => $event->to->value, 'updated_at' => $this->clock->now()->format(format: self::TIMESTAMP)],
            condition: ['order_id' => $event->orderId->value],
        )->execute();
    }

    public function onAttributed(CheckoutAttributed $event): void
    {
        $this->db->createCommand()->update(
            table: TableNames::ORDER_SUMMARY,
            columns: [
                'utm_channel' => $event->channel,
                'utm_source' => $event->source,
                'utm_medium' => $event->medium,
                'utm_campaign' => $event->campaign,
            ],
            condition: ['order_id' => $event->orderId->value],
        )->execute();
    }
}
