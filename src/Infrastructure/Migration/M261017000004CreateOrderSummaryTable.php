<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;
use App\Domain\Shared\TableNames;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Analytics' own read model of orders (OrderSummaryProjector), built from
 * domain events: the reports read it and never join another context's tables.
 */
final readonly class M261017000004CreateOrderSummaryTable implements RevertibleMigrationInterface
{
    /**
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function up(MigrationBuilder $b): void
    {
        $cb = $b->columnBuilder();
        $b->createTable(table: TableNames::ORDER_SUMMARY, columns: [
            'order_id' => $cb::string(OrderId::MAX_LENGTH)->notNull()->primaryKey(),
            'customer_id' => $cb::string(CustomerId::MAX_LENGTH)->notNull(),
            'source' => $cb::string(16)->notNull(),
            'amount_cents' => $cb::integer()->notNull(),
            'status' => $cb::string(32)->notNull(),
            'utm_channel' => $cb::string(32)->null(),
            'utm_source' => $cb::string(255)->null(),
            'utm_medium' => $cb::string(255)->null(),
            'utm_campaign' => $cb::string(255)->null(),
            'placed_at' => $cb::datetimeWithTimezone()->notNull(),
            'updated_at' => $cb::datetimeWithTimezone()->notNull(),
        ]);
        $b->createIndex(table: TableNames::ORDER_SUMMARY, name: 'idx_order_summary_source_channel', columns: ['source', 'utm_channel']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(table: TableNames::ORDER_SUMMARY);
    }
}
