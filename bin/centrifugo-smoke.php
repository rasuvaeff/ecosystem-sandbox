<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Centrifugo\PublishOptions;
use Rasuvaeff\Yii3Centrifugo\Testing\InMemoryCentrifugoClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new InMemoryCentrifugoClient();
$eventId = 'order.paid-centrifugo-demo';
$client->publish(
    channel: 'orders:centrifugo-demo',
    data: [
        'event' => 'order.paid',
        'eventId' => $eventId,
        'orderId' => 'centrifugo-demo',
        'status' => 'paid',
    ],
    options: new PublishOptions(
        idempotencyKey: $eventId,
        tags: ['source' => 'rasuvaeff-sandbox'],
    ),
);

$published = $client->publishedTo('orders:centrifugo-demo');
if (count($published) !== 1 || $published[0]['eventId'] !== $eventId) {
    throw new RuntimeException('Centrifugo publication projection was not recorded');
}

$call = $client->calls()[0] ?? null;
$options = $call['params']['options'] ?? null;
if (!$options instanceof PublishOptions || $options->toPayload()['idempotency_key'] !== $eventId) {
    throw new RuntimeException('Centrifugo idempotency key was not attached');
}

echo "CENTRIFUGO PASS: channel=orders:centrifugo-demo event=order.paid idempotency={$eventId}\n";
