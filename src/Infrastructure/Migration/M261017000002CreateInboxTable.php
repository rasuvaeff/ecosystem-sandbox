<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use App\Domain\Shared\TableNames;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Which consumer has handled which outbox message (DbInbox).
 */
final readonly class M261017000002CreateInboxTable implements RevertibleMigrationInterface
{
    /**
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function up(MigrationBuilder $b): void
    {
        $cb = $b->columnBuilder();
        $b->createTable(table: TableNames::INBOX, columns: [
            'consumer' => $cb::string(64)->notNull(),
            'message_id' => $cb::string(64)->notNull(),
            'processed_at' => $cb::datetimeWithTimezone()->notNull(),
        ]);
        $b->addPrimaryKey(table: TableNames::INBOX, name: 'pk_inbox', columns: ['consumer', 'message_id']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(table: TableNames::INBOX);
    }
}
