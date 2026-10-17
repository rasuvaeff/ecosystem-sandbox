<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Outbox;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

/**
 * One Processor, several message types, one consumer each: the payment
 * worker takes the payment requests and the reconciliations it gives rise
 * to. A type with no consumer is terminal.
 */
final readonly class RoutingPublisher implements PublisherInterface
{
    /**
     * @param array<string, PublisherInterface> $consumers keyed by outbox type
     */
    public function __construct(private array $consumers) {}

    public function publish(OutboxMessage $message): void
    {
        $consumer = $this->consumers[$message->getType()]
            ?? throw PublishException::terminal(message: 'Unsupported outbox message type', outboxMessage: $message);
        $consumer->publish($message);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(array: $this->consumers);
    }
}
