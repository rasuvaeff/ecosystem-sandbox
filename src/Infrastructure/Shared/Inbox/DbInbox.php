<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Inbox;

use App\Application\Shared\Inbox\Inbox;
use App\Domain\Shared\TableNames;
use Psr\Clock\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * The inbox as a PostgreSQL table keyed by (consumer, message id).
 */
final readonly class DbInbox implements Inbox
{
    public const string TABLE = TableNames::INBOX;

    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
    ) {}

    public function handled(string $consumer, string $messageId): bool
    {
        return new Query(db: $this->db)
            ->from(tables: self::TABLE)
            ->where(condition: ['consumer' => $consumer, 'message_id' => $messageId])
            ->exists();
    }

    public function record(string $consumer, string $messageId): void
    {
        $this->db->createCommand()->upsert(
            table: self::TABLE,
            insertColumns: ['consumer' => $consumer, 'message_id' => $messageId, 'processed_at' => $this->clock->now()->format(format: 'Y-m-d H:i:sP')],
            updateColumns: false,
        )->execute();
    }
}
