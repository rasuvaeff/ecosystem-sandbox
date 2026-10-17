<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowTimeline\Data;

use App\Endpoint\Rest\Ordering\Shared\Data\TransitionResult;
use OpenApi\Attributes as OA;

/**
 * The workflow transition history of one order, oldest first.
 */
#[OA\Schema(schema: 'OrderTimeline', required: ['orderId', 'events'], properties: [
    new OA\Property(
        property: 'orderId',
        type: 'string',
        maxLength: 64,
        minLength: 1,
    ),
    new OA\Property(
        property: 'events',
        type: 'array',
        items: new OA\Items(
            required: ['transition', 'from', 'to', 'at'],
            properties: [
                new OA\Property(
                    property: 'transition',
                    type: 'string',
                    enum: ['start_payment', 'mark_paid', 'mark_failed'],
                ),
                new OA\Property(
                    property: 'from',
                    type: 'string',
                    enum: ['pending', 'processing', 'paid', 'payment_failed'],
                ),
                new OA\Property(
                    property: 'to',
                    type: 'string',
                    enum: ['pending', 'processing', 'paid', 'payment_failed'],
                ),
                new OA\Property(
                    property: 'at',
                    type: 'string',
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
], type: 'object', additionalProperties: false)]
final readonly class OrderTimelineResult implements \JsonSerializable
{
    /**
     * @param list<TransitionResult> $events
     */
    public function __construct(
        public string $orderId,
        public array $events,
    ) {}

    /**
     * @return array{orderId: string, events: list<TransitionResult>}
     */
    public function jsonSerialize(): array
    {
        return ['orderId' => $this->orderId, 'events' => $this->events];
    }
}
