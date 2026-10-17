<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ListOrders;

use App\Application\Ordering\GetOrder\OrderView;
use App\Application\Ordering\ListOrders\ListOrdersHandler;
use App\Endpoint\Rest\Ordering\ListOrders\Data\OrderListResult;
use App\Endpoint\Rest\Ordering\Shared\Data\OrderResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * A read model with whitelisted filters: a filter that is not one of them,
 * or does not parse, is ignored rather than refused.
 */
final readonly class Action
{
    public function __construct(
        private ListOrdersHandler $orders,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders',
        operationId: 'orders.list',
        summary: 'List orders with safe read-model filters',
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['pending', 'processing', 'paid', 'payment_failed'],
                ),
            ),
            new OA\Parameter(
                name: 'state',
                description: 'open (pending or with the bank) or settled (paid or failed): the domain policies OrderPolicies::open()/settled(), translated to SQL',
                in: 'query',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['open', 'settled'],
                ),
            ),
            new OA\Parameter(
                name: 'minAmountCents',
                in: 'query',
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 0,
                ),
            ),
            new OA\Parameter(
                name: 'customerId',
                in: 'query',
                schema: new OA\Schema(
                    type: 'string',
                    maxLength: 64,
                    minLength: 1,
                ),
            ),
            new OA\Parameter(
                name: 'sort',
                description: 'amount (largest first, the default) or recent (newest first)',
                in: 'query',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['amount', 'recent'],
                ),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                schema: new OA\Schema(
                    type: 'integer',
                    maximum: 100,
                    minimum: 1,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Filtered order list',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['items', 'count'],
                            properties: [
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    items: new OA\Items(
                                        ref: '#/components/schemas/Order',
                                    ),
                                ),
                                new OA\Property(
                                    property: 'count',
                                    type: 'integer',
                                ),
                            ],
                            type: 'object',
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(Input $input): ResponseInterface
    {
        $items = $this->orders->handle(query: $input->createQuery());

        return $this->responseFactory->success(data: new OrderListResult(
            items: array_map(callback: static fn (OrderView $order): OrderResult => OrderResult::fromView(order: $order), array: $items),
        ));
    }
}
