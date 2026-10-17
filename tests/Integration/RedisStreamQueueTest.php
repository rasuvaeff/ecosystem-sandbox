<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Shared\Queue\RedisStreamQueue;
use Predis\Client;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Queue\Message\Serializer\JsonMessageEncoder;
use Yiisoft\Queue\Message\Serializer\MessageSerializer;

#[Test]
#[CoversNothing]
final class RedisStreamQueueTest
{
    public function abandonedMessageIsReclaimedAndAcknowledged(): void
    {
        $redis = new Client((string) (getenv('REDIS_URL') ?: 'redis://redis:6379'));
        $stream = 'sandbox:testo:' . bin2hex(random_bytes(length: 4));
        $queue = new RedisStreamQueue(
            redis: $redis,
            serializer: new MessageSerializer(new JsonMessageEncoder()),
            stream: $stream,
            group: 'testo-workers',
        );
        $queue->ensureGroup();
        $queue->push(new GenericMessage(type: 'order.paid', payload: ['orderId' => 'testo-redis']));

        $read = $queue->read(consumer: 'worker-a');
        Assert::same(count($read), expected: 1);
        Assert::same($read[0]['message']->getType(), expected: 'order.paid');

        $claimed = $queue->reclaim(consumer: 'worker-b');
        Assert::same(count($claimed), expected: 1);
        $queue->ack($claimed[0]['id']);
        $pending = $redis->executeRaw(['XPENDING', $stream, 'testo-workers']);

        Assert::same((int) ($pending[0] ?? 0), expected: 0);
    }
}
