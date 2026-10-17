<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowLifecycle;

use App\Application\Ordering\DescribeLifecycle\LifecycleDescriber;
use App\Endpoint\Rest\Ordering\ShowLifecycle\Data\LifecycleResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private LifecycleDescriber $lifecycle,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/workflows/order',
        operationId: 'workflows.order',
        summary: 'The order workflow\'s places and transitions as the runtime holds them',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Workflow graph',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['name', 'initial', 'places', 'transitions'],
                            properties: [
                                new OA\Property(
                                    property: 'name',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'initial',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'string',
                                    ),
                                ),
                                new OA\Property(
                                    property: 'places',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'string',
                                    ),
                                ),
                                new OA\Property(
                                    property: 'transitions',
                                    type: 'array',
                                    items: new OA\Items(
                                        required: ['name', 'from', 'to'],
                                        properties: [
                                            new OA\Property(
                                                property: 'name',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'from',
                                                type: 'array',
                                                items: new OA\Items(
                                                    type: 'string',
                                                ),
                                            ),
                                            new OA\Property(
                                                property: 'to',
                                                type: 'array',
                                                items: new OA\Items(
                                                    type: 'string',
                                                ),
                                            ),
                                        ],
                                        type: 'object',
                                        additionalProperties: false,
                                    ),
                                ),
                            ],
                            type: 'object',
                            additionalProperties: false,
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(): ResponseInterface
    {
        return $this->responseFactory->success(data: LifecycleResult::fromDescription(description: $this->lifecycle->describe()));
    }
}
