<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(WeightedHashAssignmentStrategy::class)]
final class PlatformContractsTest
{
    public function assignmentIsStableForTheSameSubject(): void
    {
        $provider = new ConfigExperimentProvider([
            'checkout' => ['salt' => 'test', 'fallbackVariant' => 'control', 'variants' => ['control' => 50, 'green' => 50]],
        ]);
        $experiments = $provider->getExperiments();
        $strategy = new WeightedHashAssignmentStrategy();

        Assert::same(
            $strategy->assign($experiments['checkout']->salt, subjectId: 'customer-1', variants: $experiments['checkout']->variants),
            $strategy->assign($experiments['checkout']->salt, subjectId: 'customer-1', variants: $experiments['checkout']->variants),
        );
    }

    public function retryableFailureReturnsToPendingAndRedelivers(): void
    {
        $storage = new InMemoryStorage();
        $message = OutboxMessage::create(type: 'payment.requested', payload: '{}', id: 'retry-1');
        $storage->save($message);
        $publisher = Understudy::for(PublisherInterface::class);
        $calls = 0;
        when(static fn () => $publisher->publish(Arg::any()))
            ->answers(static function () use (&$calls): never {
                ++$calls;

                throw new PublishException(message: 'temporary', outboxMessage: OutboxMessage::create(type: 'payment.requested', payload: '{}', id: 'retry-1'));
            });
        $processor = new Processor(
            storage: $storage,
            publisher: $publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 2, delaySeconds: 0),
            clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
        );

        $first = $processor->process();
        $second = $processor->process();

        Assert::same($first->failed, expected: 1);
        Assert::same($second->failed, expected: 1);
        Assert::same($calls, expected: 2);
        Assert::same($storage->getById(id: 'retry-1')?->getStatus(), OutboxStatus::Failed);
    }

    public function terminalFailureDoesNotRetry(): void
    {
        $storage = new InMemoryStorage();
        $message = OutboxMessage::create(type: 'payment.requested', payload: '{}', id: 'terminal-1');
        $storage->save($message);
        $publisher = Understudy::for(PublisherInterface::class);
        when(static fn () => $publisher->publish(Arg::any()))
            ->throws(PublishException::terminal(message: 'bad payload', outboxMessage: $message));
        $result = new Processor(
            storage: $storage,
            publisher: $publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
        )->process();

        Assert::same($result->failed, expected: 1);
        Assert::same($storage->getById(id: 'terminal-1')?->getStatus(), OutboxStatus::Failed);
    }
}
