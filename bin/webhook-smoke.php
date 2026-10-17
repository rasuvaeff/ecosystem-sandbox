<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Rasuvaeff\Yii3Webhooks\WebhookVerifier;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class VerifyingWebhookDispatcher implements WebhookDispatcher
{
    public function __construct(private HmacSha256Signer $signer) {}

    public function dispatch(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
    {
        $signature = $this->signer->sign(
            payload: $event->getPayload(),
            secret: $endpoint->getSecret(),
            timestamp: $event->getOccurredAt()->getTimestamp(),
            eventId: $event->getId(),
        );
        $parsed = WebhookSignature::fromHeaderValue($signature->toHeaderValue());
        $verified = new WebhookVerifier(
            signer: $this->signer,
            clock: new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('@1750000000');
                }
            },
            toleranceSeconds: 0,
        )->verify(
            payload: $event->getPayload(),
            secret: $endpoint->getSecret(),
            signature: $parsed,
            eventId: $event->getId(),
        );

        if (!$verified) {
            throw new \RuntimeException('Webhook signature verification failed');
        }

        return WebhookDelivery::create(event: $event, endpoint: $endpoint, createdAt: $event->getOccurredAt())
            ->withAttempt(at: $event->getOccurredAt())
            ->withStatus(WebhookDeliveryStatus::Delivered);
    }
}

$occurredAt = new \DateTimeImmutable('@1750000000');
$message = OutboxMessage::create(
    type: 'order.paid',
    payload: json_encode(['orderId' => 'webhook-order-1', 'status' => 'paid'], JSON_THROW_ON_ERROR),
    aggregateId: 'webhook-order-1',
    createdAt: $occurredAt,
    id: 'webhook-event-1',
);
$endpoint = new WebhookEndpoint(
    url: 'https://partner.example.com/orders',
    secret: 'sandbox-webhook-secret',
);
$storage = new InMemoryDeliveryStorage();
$publisher = new OutboxWebhookPublisher(
    dispatcher: new VerifyingWebhookDispatcher(new HmacSha256Signer()),
    endpointProvider: new ConfigWebhookEndpointProvider(['order.paid' => [$endpoint]]),
    deliveryStorage: $storage,
);
$publisher->publish($message);
$deliveries = iterator_to_array($storage);
$delivery = reset($deliveries);

if (!$delivery instanceof WebhookDelivery) {
    throw new \RuntimeException('Webhook delivery was not stored');
}

printf(
    "WEBHOOK PASS: event=%s status=%s attempts=%d signature=verified\n",
    $delivery->getEventType(),
    $delivery->getStatus()->value,
    $delivery->getAttempts(),
);
