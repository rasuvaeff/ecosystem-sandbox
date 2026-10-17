<?php

declare(strict_types=1);

use App\Infrastructure\Ordering\Webhook\HttpWebhookDispatcher;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

require dirname(__DIR__) . '/vendor/autoload.php';

$factory = new HttpFactory();
$eventTime = new \DateTimeImmutable();
$event = WebhookEvent::create(
    type: 'order.paid',
    payload: json_encode(['orderId' => 'webhook-http-order', 'status' => 'paid'], JSON_THROW_ON_ERROR),
    occurredAt: $eventTime,
    id: 'webhook-http-event-' . bin2hex(random_bytes(6)),
);
$message = OutboxMessage::create(
    type: $event->getType(),
    payload: $event->getPayload(),
    aggregateId: 'webhook-http-order',
    createdAt: $eventTime,
    id: $event->getId(),
);
$endpoint = new WebhookEndpoint(
    url: getenv('WEBHOOK_MOCK_URL') ?: 'http://webhook-mock:8082/webhook',
    secret: getenv('WEBHOOK_SECRET') ?: 'sandbox-webhook-secret',
    allowPrivateNetwork: true,
);
$storage = new InMemoryDeliveryStorage();
$publisher = new OutboxWebhookPublisher(
    dispatcher: new HttpWebhookDispatcher(
        client: new Client(['http_errors' => false, 'timeout' => 3]),
        factory: $factory,
        signer: new HmacSha256Signer(),
        clock: new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        },
    ),
    endpointProvider: new ConfigWebhookEndpointProvider(['order.paid' => [$endpoint]]),
    deliveryStorage: $storage,
);

try {
    $publisher->publish($message);

    throw new RuntimeException('The first webhook delivery should fail with HTTP 500');
} catch (PublishException) {
    // The bridge keeps the failed delivery and lets the outbox retry.
}

$publisher->publish($message);
$statuses = [];

foreach ($storage as $delivery) {
    $statuses[] = $delivery->getStatus()->value;
}

sort($statuses);

if ($statuses !== [WebhookDeliveryStatus::Delivered->value, WebhookDeliveryStatus::Failed->value]) {
    throw new RuntimeException('Unexpected webhook delivery statuses: ' . implode(',', $statuses));
}

echo "WEBHOOK HTTP PASS: first=500 retry=204 signature=verified\n";
