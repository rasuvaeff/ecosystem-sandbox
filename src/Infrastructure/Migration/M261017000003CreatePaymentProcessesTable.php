<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\TableNames;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The payment saga's state, one row per order (PaymentProcess).
 */
final readonly class M261017000003CreatePaymentProcessesTable implements RevertibleMigrationInterface
{
    /**
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function up(MigrationBuilder $b): void
    {
        $cb = $b->columnBuilder();
        $b->createTable(table: TableNames::PAYMENT_PROCESSES, columns: [
            'order_id' => $cb::string(OrderId::MAX_LENGTH)->notNull()->primaryKey(),
            'amount_cents' => $cb::integer()->notNull(),
            'gateway_key' => $cb::string(128)->notNull(),
            'status' => $cb::string(32)->notNull(),
            'created_at' => $cb::datetimeWithTimezone()->notNull(),
            'updated_at' => $cb::datetimeWithTimezone()->notNull(),
        ]);
        $b->createIndex(table: TableNames::PAYMENT_PROCESSES, name: 'idx_payment_processes_status', columns: ['status']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(table: TableNames::PAYMENT_PROCESSES);
    }
}
