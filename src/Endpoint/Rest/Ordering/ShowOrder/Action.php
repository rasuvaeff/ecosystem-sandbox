<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowOrder;

use App\Application\Ordering\GetOrder\GetOrderHandler;
use App\Application\Ordering\GetOrder\GetOrderQuery;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Endpoint\Rest\Ordering\Shared\Data\OrderResult;
use App\Endpoint\Rest\Ordering\Shared\OrderIdFromRoute;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class Action
{
    public function __construct(
        private GetOrderHandler $orders,
        private ResponseFactory $responseFactory,
        private CurrentRoute $currentRoute,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders/{id}',
        operationId: 'orders.get',
        summary: 'Read an order',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
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
                description: 'Order state',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/Order',
                        ),
                    ),
                ],
            ),
            new OA\Response(
                response: 404,
                description: 'Order not found',
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
    public function __invoke(): ResponseInterface
    {
        try {
            $order = $this->orders->handle(query: new GetOrderQuery(orderId: OrderIdFromRoute::of(route: $this->currentRoute)));
        } catch (OrderNotFoundException) {
            throw Problem::orderNotFound();
        }

        return $this->responseFactory->success(data: OrderResult::fromView(order: $order));
    }
}
