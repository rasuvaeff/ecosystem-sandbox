<?php

declare(strict_types=1);

namespace App\Application\Shared\Inbox;

/**
 * Which consumer has already handled which outbox message. Delivery is
 * at-least-once; with the inbox a consumer acts on a message once it is
 * known to be handled, and only the crash window between the effect and
 * the record is left to the consumer's own deduplication.
 */
interface Inbox
{
    /**
     * @param non-empty-string $consumer
     */
    public function handled(string $consumer, string $messageId): bool;

    /**
     * Records the message as handled; recording it twice is a no-op.
     *
     * @param non-empty-string $consumer
     */
    public function record(string $consumer, string $messageId): void;
}
