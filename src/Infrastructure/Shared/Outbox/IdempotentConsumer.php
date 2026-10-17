<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Outbox;

use App\Application\Shared\Inbox\Inbox;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;

/**
 * A consumer that is not run again for a message it has handled. The effect
 * runs outside any transaction of ours — a sink calling Centrifugo, a
 * webhook or ClickHouse must not hold a PostgreSQL transaction open across
 * a network call — and is recorded after it succeeded. A failed effect is
 * not recorded, so the next delivery runs it again; a crash between the
 * effect and the record is the one window left to the consumer's own
 * deduplication (an idempotency key, a natural key, ReplacingMergeTree).
 *
 * In the result fan-out this is what keeps a sink that already delivered
 * from delivering again when another sink makes the message retry.
 */
final readonly class IdempotentConsumer implements PublisherInterface
{
    /**
     * @param non-empty-string $name the consumer's key in the inbox
     */
    public function __construct(
        private string $name,
        private PublisherInterface $consumer,
        private Inbox $inbox,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        if ($this->inbox->handled(consumer: $this->name, messageId: $message->getId())) {
            return;
        }
        $this->consumer->publish($message);
        $this->inbox->record(consumer: $this->name, messageId: $message->getId());
    }
}
