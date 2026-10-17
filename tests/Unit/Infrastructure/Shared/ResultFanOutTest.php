<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Shared;

use App\Infrastructure\Shared\Outbox\ResultFanOut;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * One claimer, several subscribers: a failing sink never keeps the others
 * from their delivery, and the message is retried as a whole.
 */
#[Test]
#[Covers(ResultFanOut::class)]
final class ResultFanOutTest
{
    /**
     * @param list<'ok'|'retryable'|'error'> $behaviours one per sink, in order
     */
    #[Property(runs: 150)]
    public function everySinkIsTriedAndAnyFailureRetriesTheMessage(array $behaviours): void
    {
        $failing = array_filter(array: $behaviours, callback: static fn (string $b): bool => $b !== 'ok');
        Classify::cover($failing === [], label: 'all delivered', minPercent: 5.0);
        Classify::cover($failing !== [], label: 'some failed', minPercent: 30.0);
        $sinks = [];
        foreach ($behaviours as $index => $behaviour) {
            $sink = Understudy::for(PublisherInterface::class);
            match ($behaviour) {
                'ok' => null,
                'retryable' => when(static fn () => $sink->publish(Arg::any()))->throwsWith(static fn (): \Throwable => new PublishException(message: 'busy', outboxMessage: self::message())),
                'error' => when(static fn () => $sink->publish(Arg::any()))->throws(new \RuntimeException(message: 'boom')),
            };
            $sinks['sink-' . $index] = $sink;
        }

        try {
            new ResultFanOut(sinks: $sinks)->publish(self::message());
            Assert::same($failing, []);
        } catch (PublishException $exception) {
            Assert::true($failing !== []);
            Assert::false($exception->isTerminal());
            foreach (array_keys($failing) as $index) {
                Assert::string($exception->getMessage())->contains('sink-' . $index . ':');
            }
        }

        foreach ($sinks as $sink) {
            verify(static fn () => $sink->publish(Arg::any()), times: 1);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everySinkIsTriedAndAnyFailureRetriesTheMessageGenerators(): array
    {
        return ['behaviours' => Gen::nonEmptyArrayOf(
            Gen::frequency([[3, Gen::elements(['ok'])], [1, Gen::elements(['retryable'])], [1, Gen::elements(['error'])]]),
            maxSize: 4,
        )];
    }

    /** A message no sink can ever take stops at once, for all of them. */
    public function aTerminalFailureIsPassedOnUnchanged(): void
    {
        $terminal = PublishException::terminal(message: 'unsupported', outboxMessage: self::message());
        $first = Understudy::for(PublisherInterface::class);
        when(static fn () => $first->publish(Arg::any()))->throws($terminal);

        try {
            new ResultFanOut(sinks: ['first' => $first, 'second' => Understudy::for(PublisherInterface::class)])->publish(self::message());
            Assert::fail(message: 'A terminal failure must reach the outbox');
        } catch (PublishException $exception) {
            Assert::same($exception, $terminal);
        }
    }

    private static function message(): OutboxMessage
    {
        return OutboxMessage::create(type: 'order.paid', payload: '{"orderId":"order-1"}', id: 'event-1');
    }
}
