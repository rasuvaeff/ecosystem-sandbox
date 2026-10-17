<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Shared\Inbox\DbInbox;
use Psr\Container\ContainerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * The inbox on the real PostgreSQL: a record is per consumer, recording
 * twice is a no-op, and the time is the clock's.
 */
#[Test]
#[Covers(DbInbox::class)]
final class DbInboxTest
{
    public function aRecordIsPerConsumerAndIdempotent(): void
    {
        $db = $this->db();
        $inbox = new DbInbox(db: $db, clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-03-01T10:00:00+00:00')));
        $messageId = 'testo-inbox-' . bin2hex(random_bytes(length: 4));

        Assert::false($inbox->handled(consumer: 'centrifugo', messageId: $messageId));
        $inbox->record(consumer: 'centrifugo', messageId: $messageId);
        $inbox->record(consumer: 'centrifugo', messageId: $messageId);

        Assert::true($inbox->handled(consumer: 'centrifugo', messageId: $messageId));
        Assert::false($inbox->handled(consumer: 'webhooks', messageId: $messageId));
        Assert::same(
            new Query(db: $db)->select(columns: ['processed_at'])->from(tables: DbInbox::TABLE)->where(condition: ['message_id' => $messageId])->column(),
            ['2026-03-01 10:00:00+00'],
        );
    }

    private function db(): ConnectionInterface
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, levels: 2) . '/bin/support/container.php';
        $db = $container->get(ConnectionInterface::class);
        \assert($db instanceof ConnectionInterface);

        return $db;
    }
}
