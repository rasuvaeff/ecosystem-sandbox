<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger;

use OpenApi\Attributes as OA;

/**
 * A property whose value is fixed (`const`, OpenAPI 3.1). swagger-php 4
 * models the keyword but its Property attribute takes no argument for it.
 */
final class ConstProperty extends OA\Property
{
    public function __construct(string $property, string|int|bool $const)
    {
        parent::__construct(property: $property);
        $this->const = $const;
    }
}
