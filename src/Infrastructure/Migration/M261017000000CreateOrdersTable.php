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

final readonly class M261017000000CreateOrdersTable implements RevertibleMigrationInterface
{
    /**
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function up(MigrationBuilder $b): void
    {
        $cb = $b->columnBuilder();
        $b->createTable(table: TableNames::ORDERS, columns: [
            'id' => $cb::string(OrderId::MAX_LENGTH)->notNull()->primaryKey(),
            'customer_id' => $cb::string(CustomerId::MAX_LENGTH)->notNull(),
            'amount_cents' => $cb::integer()->notNull(),
            'status' => $cb::string(32)->notNull(),
            'created_at' => $cb::datetimeWithTimezone()->notNull(),
            'updated_at' => $cb::datetimeWithTimezone()->notNull(),
        ]);
        $b->createIndex(table: TableNames::ORDERS, name: 'idx_orders_status', columns: ['status']);
        $b->createIndex(table: TableNames::ORDERS, name: 'idx_orders_created_at', columns: ['created_at']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(table: TableNames::ORDERS);
    }
}
