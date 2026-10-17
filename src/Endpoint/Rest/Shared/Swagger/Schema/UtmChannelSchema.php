<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger\Schema;

use OpenApi\Attributes as OA;

/**
 * The `UtmChannel` component of the published OpenAPI document; attributes only.
 */
#[OA\Schema(
    schema: 'UtmChannel',
    type: 'string',
    enum: ['paid', 'organic', 'social', 'email', 'referral', 'direct', 'other'],
)]
final readonly class UtmChannelSchema {}
