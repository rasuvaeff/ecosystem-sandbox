<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Payments\ShowGatewayProtection\Data;

use OpenApi\Attributes as OA;

/**
 * The payment gateway's circuit breaker and bulkhead, read live from Redis.
 */
#[OA\Schema(schema: 'ResilienceState', required: ['name', 'breaker', 'bulkhead', 'now'], properties: [
    new OA\Property(
        property: 'name',
        type: 'string',
    ),
    new OA\Property(
        property: 'now',
        type: 'string',
    ),
    new OA\Property(
        property: 'breaker',
        required: ['state', 'readyToProbe', 'failures', 'successes', 'rejected', 'failureThreshold', 'cooldownSeconds', 'openedAt', 'halfOpenAt'],
        properties: [
            new OA\Property(
                property: 'state',
                type: 'string',
                enum: ['closed', 'open', 'half-open'],
            ),
            new OA\Property(
                property: 'readyToProbe',
                type: 'boolean',
            ),
            new OA\Property(
                property: 'failures',
                type: 'integer',
                minimum: 0,
            ),
            new OA\Property(
                property: 'successes',
                type: 'integer',
                minimum: 0,
            ),
            new OA\Property(
                property: 'rejected',
                type: 'integer',
                minimum: 0,
            ),
            new OA\Property(
                property: 'failureThreshold',
                required: ['failures', 'window', 'withinSeconds'],
                properties: [
                    new OA\Property(
                        property: 'failures',
                        type: 'integer',
                    ),
                    new OA\Property(
                        property: 'window',
                        type: 'integer',
                    ),
                    new OA\Property(
                        property: 'withinSeconds',
                        type: 'number',
                    ),
                ],
                type: 'object',
                additionalProperties: false,
            ),
            new OA\Property(
                property: 'cooldownSeconds',
                type: 'number',
            ),
            new OA\Property(
                property: 'openedAt',
                type: ['string', 'null'],
            ),
            new OA\Property(
                property: 'halfOpenAt',
                type: ['string', 'null'],
            ),
        ],
        type: 'object',
        additionalProperties: false,
    ),
    new OA\Property(
        property: 'bulkhead',
        required: ['maxConcurrent', 'inUse'],
        properties: [
            new OA\Property(
                property: 'maxConcurrent',
                type: 'integer',
                minimum: 1,
            ),
            new OA\Property(
                property: 'inUse',
                type: 'integer',
                minimum: 0,
            ),
        ],
        type: 'object',
        additionalProperties: false,
    ),
], type: 'object', additionalProperties: false)]
final readonly class ResilienceStateResult implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $breaker
     * @param array{maxConcurrent: int, inUse: int} $bulkhead
     */
    public function __construct(
        public string $name,
        public array $breaker,
        public array $bulkhead,
        public string $now,
    ) {}

    /**
     * @param array{name: string, breaker: array<string, mixed>, bulkhead: array{maxConcurrent: int, inUse: int}, now: string} $state
     */
    public static function fromArray(array $state): self
    {
        return new self(name: $state['name'], breaker: $state['breaker'], bulkhead: $state['bulkhead'], now: $state['now']);
    }

    /**
     * @return array{name: string, breaker: array<string, mixed>, bulkhead: array{maxConcurrent: int, inUse: int}, now: string}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'breaker' => $this->breaker, 'bulkhead' => $this->bulkhead, 'now' => $this->now];
    }
}
