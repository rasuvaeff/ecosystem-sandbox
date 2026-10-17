<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Outbox;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

/**
 * One outbox message, several subscribers. The outbox hands a message to one
 * claimer, so the realtime projection, the webhooks and the analytics export
 * cannot each run a Processor of their own on the same types; they share this
 * one instead.
 *
 * Every sink is tried even after one failed, then the message is retried as a
 * whole: the sinks that already delivered see it again, so each dedupes on
 * the message id (Centrifugo idempotency key, webhook event id,
 * ReplacingMergeTree). A sink that keeps failing exhausts the message for all
 * of them — the price of one claimer.
 */
final readonly class ResultFanOut implements PublisherInterface
{
    /** @param array<string, PublisherInterface> $sinks keyed by a name for the failure message */
    public function __construct(private array $sinks) {}

    public function publish(OutboxMessage $message): void
    {
        $failures = [];
        foreach ($this->sinks as $name => $sink) {
            try {
                $sink->publish($message);
            } catch (PublishException $exception) {
                if ($exception->isTerminal()) {
                    throw $exception;
                }
                $failures[] = $name . ': ' . $exception->getMessage();
            } catch (\Throwable $exception) {
                $failures[] = $name . ': ' . $exception->getMessage();
            }
        }

        if ($failures !== []) {
            throw new PublishException(message: 'Result delivery failed for ' . implode(separator: '; ', array: $failures), outboxMessage: $message);
        }
    }
}
