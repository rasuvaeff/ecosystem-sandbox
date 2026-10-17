<?php

declare(strict_types=1);

namespace App\Application\Shared\Integration;

use Rasuvaeff\Schema\SchemaInterface;

/**
 * A fact one context publishes to the others through the outbox. The type
 * and the payload shape are a frozen contract: a new shape is a new version
 * under a new type, never an edit of this one. schema() is the one
 * definition both sides use — the producer's toPayload() must satisfy it and
 * the consumer parses with it (IntegrationEventContractTest holds both).
 */
interface IntegrationEvent
{
    /**
     * @return non-empty-string the outbox type
     */
    public static function type(): string;

    /**
     * @return positive-int
     */
    public static function version(): int;

    /**
     * Parses a decoded payload into an instance of the event.
     */
    public static function schema(): SchemaInterface;

    /**
     * @return non-empty-string the outbox aggregate id
     */
    public function aggregateId(): string;

    /**
     * @return array<string, mixed> the payload, exactly as it goes on the wire
     */
    public function toPayload(): array;
}
