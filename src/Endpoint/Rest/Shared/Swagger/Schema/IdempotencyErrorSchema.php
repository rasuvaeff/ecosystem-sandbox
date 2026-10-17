<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger\Schema;

use OpenApi\Attributes as OA;

/**
 * The `IdempotencyError` component of the published OpenAPI document; attributes only.
 */
#[OA\Schema(schema: 'IdempotencyError', required: ['error', 'message'], properties: [
    new OA\Property(
        property: 'error',
        type: 'string',
    ),
    new OA\Property(
        property: 'message',
        type: 'string',
    ),
], type: 'object', additionalProperties: false)]
final readonly class IdempotencyErrorSchema {}
