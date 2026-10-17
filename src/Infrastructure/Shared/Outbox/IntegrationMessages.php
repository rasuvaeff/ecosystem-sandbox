<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Outbox;

use App\Application\Shared\Integration\Exception\InvalidIntegrationEvent;
use App\Application\Shared\Integration\Exception\UnknownIntegrationEvent;
use App\Application\Shared\Integration\IntegrationEvent;
use App\Application\Shared\Integration\IntegrationEventRegistry;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;

/**
 * An outbox message as the integration event a consumer expects. A message
 * of another type, or whose payload breaks the schema, will never become
 * consumable however often it is retried: it is a terminal failure.
 */
final readonly class IntegrationMessages
{
    public function __construct(private IntegrationEventRegistry $registry) {}

    /**
     * @template T of IntegrationEvent
     *
     * @param class-string<T> $expected
     *
     * @throws PublishException
     *
     * @return T
     */
    public function parse(OutboxMessage $message, string $expected): IntegrationEvent
    {
        try {
            $event = $this->registry->parse(type: $message->getType(), payload: $message->getPayload());
        } catch (UnknownIntegrationEvent|InvalidIntegrationEvent $exception) {
            throw PublishException::terminal(message: $exception->getMessage(), outboxMessage: $message, previous: $exception);
        }
        if (!$event instanceof $expected) {
            throw PublishException::terminal(message: 'Unsupported outbox message type', outboxMessage: $message);
        }

        return $event;
    }
}
