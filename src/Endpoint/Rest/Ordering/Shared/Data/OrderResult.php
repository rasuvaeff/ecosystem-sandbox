<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Shared\Data;

use App\Application\Ordering\GetOrder\OrderView;
use OpenApi\Attributes as OA;

/**
 * An order as the API shows it: GET /orders/{id}, the list and the evidence.
 */
#[OA\Schema(schema: 'Order', required: ['id', 'customerId', 'amountCents', 'status'], properties: [
    new OA\Property(
        property: 'id',
        type: 'string',
        maxLength: 64,
        minLength: 1,
    ),
    new OA\Property(
        property: 'customerId',
        type: 'string',
        maxLength: 64,
        minLength: 1,
    ),
    new OA\Property(
        property: 'amountCents',
        type: 'integer',
        maximum: 100000000,
        minimum: 1,
    ),
    new OA\Property(
        property: 'status',
        type: 'string',
        enum: ['pending', 'processing', 'paid', 'payment_failed'],
    ),
], type: 'object', additionalProperties: false)]
final readonly class OrderResult implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $customerId,
        public int $amountCents,
        public string $status,
    ) {}

    public static function fromView(OrderView $order): self
    {
        return new self(id: $order->orderId->value, customerId: $order->customerId->value, amountCents: $order->amount->cents, status: $order->status->value);
    }

    /**
     * @return array{id: string, customerId: string, amountCents: int, status: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'customerId' => $this->customerId, 'amountCents' => $this->amountCents, 'status' => $this->status];
    }
}
