<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared;

use OpenApi\Attributes as OA;

/**
 * The document's header; the operations live on the actions, the component
 * schemas in Swagger/Schema.
 */
#[OA\Info(
    version: '1.0.0',
    description: 'Checkout and order read model used by the ecosystem sandbox',
    title: 'Rasuvaeff Ecosystem Sandbox API',
)]
final readonly class OpenApiInfo {}
