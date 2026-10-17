<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Webhook;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookSigner;

/**
 * Sends one signed webhook over PSR-18. A non-2xx answer or a transport error
 * becomes a Failed delivery, which the outbox bridge turns into a retry.
 */
final readonly class HttpWebhookDispatcher implements WebhookDispatcher
{
    public function __construct(
        private ClientInterface $client,
        private HttpFactory $factory,
        private WebhookSigner $signer,
        private ClockInterface $clock,
    ) {}

    public function dispatch(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
    {
        $now = $this->clock->now();
        $delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint, createdAt: $now);
        $signature = $this->signer->sign(
            payload: $event->getPayload(),
            secret: $endpoint->getSecret(),
            timestamp: $now->getTimestamp(),
            eventId: $event->getId(),
        );
        $request = $this->factory
            ->createRequest(method: 'POST', uri: $endpoint->getUrl())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Webhook-Signature', $signature->toHeaderValue())
            ->withHeader('X-Webhook-Event', $event->getType())
            ->withHeader('X-Webhook-Id', $event->getId())
            ->withBody($this->factory->createStream($event->getPayload()));

        try {
            $response = $this->client->sendRequest($request);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300
                ? $delivery->withAttempt(at: $now)->withStatus(WebhookDeliveryStatus::Delivered)
                : $delivery->withAttempt(at: $now, error: 'HTTP ' . $status)
                    ->withStatus(WebhookDeliveryStatus::Failed);
        } catch (\Throwable $exception) {
            return $delivery->withAttempt(at: $now, error: $exception->getMessage())
                ->withStatus(WebhookDeliveryStatus::Failed);
        }
    }
}
