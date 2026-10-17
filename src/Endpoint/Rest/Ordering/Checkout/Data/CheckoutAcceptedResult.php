<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Checkout\Data;

use App\Endpoint\Rest\Shared\Swagger\ConstProperty;
use OpenApi\Attributes as OA;

/**
 * The checkout's 202: the order is placed and its payment request is in the
 * outbox. `accepted` is false for a replay of an order already placed.
 */
#[OA\Schema(schema: 'CheckoutAccepted', required: ['orderId', 'status', 'accepted', 'event'], properties: [
    new OA\Property(
        property: 'orderId',
        type: 'string',
        maxLength: 64,
        minLength: 1,
    ),
    new ConstProperty(
        property: 'status',
        const: 'processing',
    ),
    new OA\Property(
        property: 'accepted',
        type: 'boolean',
    ),
    new ConstProperty(
        property: 'event',
        const: 'payment.requested',
    ),
], type: 'object', additionalProperties: false)]
final readonly class CheckoutAcceptedResult implements \JsonSerializable
{
    public const string EVENT = 'payment.requested';

    public function __construct(
        public string $orderId,
        public string $status,
        public bool $accepted,
    ) {}

    /**
     * @return array{orderId: string, status: string, accepted: bool, event: string}
     */
    public function jsonSerialize(): array
    {
        return ['orderId' => $this->orderId, 'status' => $this->status, 'accepted' => $this->accepted, 'event' => self::EVENT];
    }
}
