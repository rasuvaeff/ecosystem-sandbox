<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger\Schema;

use OpenApi\Attributes as OA;

/**
 * The `CheckoutRequest` component of the published OpenAPI document; attributes only.
 */
#[OA\Schema(schema: 'CheckoutRequest', required: ['orderId', 'customerId', 'amountCents'], properties: [
    new OA\Property(
        property: 'orderId',
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
], type: 'object', additionalProperties: false)]
final readonly class CheckoutRequestSchema {}
