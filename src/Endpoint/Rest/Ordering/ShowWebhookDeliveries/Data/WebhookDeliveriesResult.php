<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowWebhookDeliveries\Data;

/**
 * Both ends of the webhook: what the sender recorded, and the receiver's
 * journal (null while the receiver is down).
 */
final readonly class WebhookDeliveriesResult implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $deliveries
     * @param list<mixed>|null $received
     */
    public function __construct(
        public array $deliveries,
        public ?array $received,
    ) {}

    /**
     * @return array{deliveries: list<array<string, mixed>>, received: list<mixed>|null}
     */
    public function jsonSerialize(): array
    {
        return ['deliveries' => $this->deliveries, 'received' => $this->received];
    }
}
