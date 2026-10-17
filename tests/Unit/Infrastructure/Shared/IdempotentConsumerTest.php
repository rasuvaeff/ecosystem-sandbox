<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Shared;

use App\Infrastructure\Shared\Outbox\IdempotentConsumer;
use App\Tests\Support\InMemoryInbox;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * At-least-once in, once out: whatever the redeliveries, the wrapped
 * consumer's effect happens exactly once per message after its first
 * success, and a failed delivery is tried again.
 */
#[Test]
#[Covers(IdempotentConsumer::class)]
final class IdempotentConsumerTest
{
    /**
     * @param list<bool> $outcomes one per delivery of the same message: does the consumer succeed?
     */
    #[Property(runs: 200)]
    public function theEffectHappensOnceAfterTheFirstSuccess(array $outcomes): void
    {
        $succeedsAt = array_search(needle: true, haystack: $outcomes, strict: true);
        Classify::cover($succeedsAt === false, label: 'never succeeds', minPercent: 5.0);
        Classify::cover($succeedsAt !== false && $succeedsAt > 0, label: 'succeeds after a failure', minPercent: 10.0);
        $consumer = new class ($outcomes) implements PublisherInterface {
            public int $calls = 0;

            public int $effects = 0;

            /** @param list<bool> $outcomes */
            public function __construct(private readonly array $outcomes) {}

            public function publish(OutboxMessage $message): void
            {
                $succeeds = $this->outcomes[$this->calls++];
                if (!$succeeds) {
                    throw new PublishException(message: 'busy', outboxMessage: $message);
                }
                ++$this->effects;
            }
        };
        $idempotent = new IdempotentConsumer(name: 'sink', consumer: $consumer, inbox: new InMemoryInbox());
        $message = OutboxMessage::create(type: 'order.paid', payload: '{"orderId":"o-1"}', id: 'event-1');

        foreach ($outcomes as $ignored) {
            try {
                $idempotent->publish($message);
            } catch (PublishException) {
            }
        }

        Assert::same($consumer->effects, $succeedsAt === false ? 0 : 1);
        // Tried on every delivery up to the first success, never after it.
        Assert::same($consumer->calls, $succeedsAt === false ? count($outcomes) : $succeedsAt + 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theEffectHappensOnceAfterTheFirstSuccessGenerators(): array
    {
        return ['outcomes' => Gen::nonEmptyArrayOf(Gen::frequency([[1, Gen::elements([true])], [2, Gen::elements([false])]]), maxSize: 6)];
    }
}
