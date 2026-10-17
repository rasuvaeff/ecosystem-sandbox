<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowTenant;

use App\Endpoint\Rest\Operations\ShowTenant\Data\TenantResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Tenancy\Tenant;

/**
 * The tenant TenantResolutionMiddleware resolved from X-Tenant-Id; a
 * suspended one never gets here (the middleware answers 403).
 */
final readonly class Action
{
    public function __construct(private ResponseFactory $responseFactory) {}

    #[OA\Get(
        path: '/api/v1/tenant',
        operationId: 'tenant.show',
        summary: 'The tenant resolved from the X-Tenant-Id header',
        parameters: [
            new OA\Parameter(
                name: 'X-Tenant-Id',
                in: 'header',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    maxLength: 64,
                    minLength: 1,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The resolved tenant',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['id', 'name', 'status'],
                            properties: [
                                new OA\Property(
                                    property: 'id',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'name',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'status',
                                    type: 'string',
                                ),
                            ],
                            type: 'object',
                            additionalProperties: false,
                        ),
                    ),
                ],
            ),
            new OA\Response(
                response: 403,
                description: 'The tenant exists but is suspended. TenantResolutionMiddleware answers before the action, with an empty body.',
            ),
            new OA\Response(
                response: 404,
                description: 'No tenant matches the header, or it is missing',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/problem+json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/Problem',
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $request->getAttribute(Tenant::class);
        if (!$tenant instanceof Tenant) {
            throw Problem::tenantNotFound();
        }

        return $this->responseFactory->success(data: new TenantResult(id: $tenant->id, name: $tenant->name, status: $tenant->status->value));
    }
}
