<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowTimeline;

use App\Application\Ordering\GetOrderTimeline\GetOrderTimelineHandler;
use App\Application\Ordering\GetOrderTimeline\GetOrderTimelineQuery;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Endpoint\Rest\Ordering\Shared\Data\TransitionResult;
use App\Endpoint\Rest\Ordering\Shared\OrderIdFromRoute;
use App\Endpoint\Rest\Ordering\ShowTimeline\Data\OrderTimelineResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class Action
{
    public function __construct(
        private GetOrderTimelineHandler $timeline,
        private ResponseFactory $responseFactory,
        private CurrentRoute $currentRoute,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders/{id}/timeline',
        operationId: 'orders.timeline',
        summary: 'Read the workflow transition history of an order',
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
                description: 'Chronological transition log of the order',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/OrderTimeline',
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
        $orderId = OrderIdFromRoute::of(route: $this->currentRoute);

        try {
            $records = $this->timeline->handle(query: new GetOrderTimelineQuery(orderId: $orderId));
        } catch (OrderNotFoundException) {
            throw Problem::orderNotFound();
        }

        return $this->responseFactory->success(data: new OrderTimelineResult(orderId: $orderId->value, events: TransitionResult::list(records: $records)));
    }
}
