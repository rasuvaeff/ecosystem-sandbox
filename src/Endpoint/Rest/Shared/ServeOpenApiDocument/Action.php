<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\ServeOpenApiDocument;

use App\Endpoint\Rest\Shared\Swagger\OpenApiDocument;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The generated OpenAPI document, the same array the checkout validates
 * against and Swagger UI (/docs) renders.
 */
final readonly class Action
{
    public function __construct(
        private OpenApiDocument $document,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode(value: $this->document->toArray(), flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }
}
