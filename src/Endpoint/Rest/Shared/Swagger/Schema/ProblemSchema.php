<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger\Schema;

use OpenApi\Attributes as OA;

/**
 * The `Problem` component of the published OpenAPI document; attributes only.
 */
#[OA\Schema(schema: 'Problem', required: ['title', 'status'], properties: [
    new OA\Property(
        property: 'type',
        type: 'string',
    ),
    new OA\Property(
        property: 'title',
        type: 'string',
    ),
    new OA\Property(
        property: 'status',
        type: 'integer',
    ),
    new OA\Property(
        property: 'detail',
        type: 'string',
    ),
], type: 'object')]
final readonly class ProblemSchema {}
