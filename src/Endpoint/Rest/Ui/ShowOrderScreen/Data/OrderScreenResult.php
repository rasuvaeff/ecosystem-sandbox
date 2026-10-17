<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ui\ShowOrderScreen\Data;

use App\Endpoint\Rest\Ordering\ShowEvidence\Data\OrderEvidenceResult;
use App\Endpoint\Rest\Payments\ShowGatewayProtection\Data\ResilienceStateResult;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'OrderScreen', required: ['evidence', 'breaker'], properties: [
    new OA\Property(
        property: 'evidence',
        ref: '#/components/schemas/OrderEvidence',
    ),
    new OA\Property(
        property: 'breaker',
        description: 'The payment gateway\'s protection while the order still waits on the bank; null once it settled',
        oneOf: [
            new OA\Schema(
                type: 'null',
            ),
            new OA\Schema(
                ref: '#/components/schemas/ResilienceState',
            ),
        ],
    ),
], type: 'object', additionalProperties: false)]
final readonly class OrderScreenResult implements \JsonSerializable
{
    public function __construct(
        public OrderEvidenceResult $evidence,
        public ?ResilienceStateResult $breaker,
    ) {}

    /**
     * @return array{evidence: OrderEvidenceResult, breaker: ResilienceStateResult|null}
     */
    public function jsonSerialize(): array
    {
        return ['evidence' => $this->evidence, 'breaker' => $this->breaker];
    }
}
