<?php

declare(strict_types=1);

namespace App\Application\Shared\Integration;

use App\Application\Shared\Integration\Exception\InvalidIntegrationEvent;
use App\Application\Shared\Integration\Exception\UnknownIntegrationEvent;

/**
 * Every integration event the application exchanges, keyed by outbox type,
 * with its version (`payment.requested@v1`). Consumers parse through here,
 * never by hand: an unknown type or a payload outside the schema is an
 * exception the consumer turns into a terminal failure.
 */
final readonly class IntegrationEventRegistry
{
    /** @var array<non-empty-string, class-string<IntegrationEvent>> */
    private array $events;

    /**
     * @param class-string<IntegrationEvent> ...$events
     */
    public function __construct(string ...$events)
    {
        $byType = [];
        foreach ($events as $event) {
            $type = $event::type();
            if (isset($byType[$type])) {
                throw new \InvalidArgumentException(message: 'Integration event type "' . $type . '" is registered twice');
            }
            $byType[$type] = $event;
        }
        $this->events = $byType;
    }

    /**
     * @throws UnknownIntegrationEvent
     * @throws InvalidIntegrationEvent
     */
    public function parse(string $type, string $payload): IntegrationEvent
    {
        $event = $this->events[$type] ?? throw new UnknownIntegrationEvent(type: $type);

        try {
            $parsed = $event::schema()->parse(input: json_decode(json: $payload, associative: true, flags: JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            throw new InvalidIntegrationEvent(type: $type, previous: $exception);
        }
        if (!$parsed->ok || !$parsed->value instanceof $event) {
            throw new InvalidIntegrationEvent(type: $type);
        }

        return $parsed->value;
    }

    /**
     * @return list<non-empty-string> `type@vN`, one per registered event
     */
    public function contracts(): array
    {
        $contracts = [];
        foreach ($this->events as $type => $event) {
            $contracts[] = $type . '@v' . $event::version();
        }

        return $contracts;
    }

    /**
     * @return list<class-string<IntegrationEvent>>
     */
    public function events(): array
    {
        return array_values(array: $this->events);
    }
}
