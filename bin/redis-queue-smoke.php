<?php

declare(strict_types=1);

use App\Infrastructure\Shared\Queue\RedisStreamQueue;
use Predis\Client;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Queue\Message\Serializer\JsonMessageEncoder;
use Yiisoft\Queue\Message\Serializer\MessageSerializer;

require dirname(__DIR__) . '/vendor/autoload.php';

$redis = new Client((string) (getenv('REDIS_URL') ?: 'redis://redis:6379'));
$stream = 'sandbox:queue:' . bin2hex(random_bytes(4));
$queue = new RedisStreamQueue(
    redis: $redis,
    serializer: new MessageSerializer(new JsonMessageEncoder()),
    stream: $stream,
    group: 'smoke-workers',
);
$queue->ensureGroup();
$id = $queue->push(new GenericMessage('order.paid', ['orderId' => 'redis-queue-demo']));
$received = $queue->read('worker-a');
if (count($received) !== 1 || $received[0]['message']->getType() !== 'order.paid') {
    throw new RuntimeException('Redis queue did not read yiisoft/queue message');
}
$claimed = $queue->reclaim('worker-b');
if ($claimed === []) {
    throw new RuntimeException('Redis queue did not reclaim an abandoned pending message');
}
$queue->ack($claimed[0]['id']);
$pending = $redis->executeRaw(['XPENDING', $stream, 'smoke-workers']);
if ((int) ($pending[0] ?? 0) !== 0) {
    throw new RuntimeException('Redis queue did not ACK reclaimed message');
}

echo "REDIS QUEUE PASS: stream={$stream} id={$id} delivery=at-least-once reclaim=ok ack=ok\n";
