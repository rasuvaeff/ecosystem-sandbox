<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\Yii3Centrifugo\CentrifugoClient;
use Rasuvaeff\Yii3Centrifugo\PublishOptions;

require dirname(__DIR__) . '/vendor/autoload.php';

$factory = new HttpFactory();
$client = new CentrifugoClient(
    httpClient: new Client(['http_errors' => false, 'timeout' => 3]),
    requestFactory: $factory,
    streamFactory: $factory,
    apiUrl: getenv('CENTRIFUGO_API_URL') ?: 'http://centrifugo:9000',
    apiKey: getenv('CENTRIFUGO_API_KEY') ?: 'sandbox-centrifugo-key',
);
$result = $client->publish(
    channel: 'orders:realtime-smoke',
    data: ['orderId' => 'realtime-smoke', 'status' => 'paid'],
    options: new PublishOptions(idempotencyKey: 'realtime-smoke-event-1', tags: ['kind' => 'order']),
);
if (!is_array($result)) {
    throw new RuntimeException('Centrifugo publish did not return an array result');
}
echo "CENTRIFUGO HTTP PASS: channel=orders:realtime-smoke idempotency=realtime-smoke-event-1\n";
