<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger\Schema;

use OpenApi\Attributes as OA;

/**
 * The `AuditChange` component of the published OpenAPI document; attributes only.
 */
#[OA\Schema(schema: 'AuditChange', required: ['field', 'old', 'new'], properties: [
    new OA\Property(
        property: 'field',
        type: 'string',
    ),
    new OA\Property(
        property: 'old',
    ),
    new OA\Property(
        property: 'new',
    ),
], type: 'object', additionalProperties: false)]
final readonly class AuditChangeSchema {}
