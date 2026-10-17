<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\IssueRealtimeToken;

use App\Application\Ordering\GetOrder\GetOrderHandler;
use App\Application\Ordering\GetOrder\GetOrderQuery;
use App\Application\Ordering\Integration\OrderChannel;
use App\Domain\Analytics\OrderSource;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Endpoint\Rest\Ordering\IssueRealtimeToken\Data\RealtimeTokenResult;
use App\Endpoint\Rest\Ordering\Shared\OrderIdFromRoute;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Environment;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Centrifugo\Token\ConnectionTokenIssuer;
use Yiisoft\Router\CurrentRoute;

/**
 * A Centrifugo connection token subscribed to this order's channel only: the
 * browser opens `orders:<id>` and nothing else, for as long as it lives. The
 * WebSocket address travels with it, next to the channel it opens.
 */
final readonly class Action
{
    public function __construct(
        private GetOrderHandler $orders,
        private ConnectionTokenIssuer $tokens,
        private ResponseFactory $responseFactory,
        private CurrentRoute $currentRoute,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders/{id}/realtime-token',
        operationId: 'orders.realtimeToken',
        summary: 'A Centrifugo connection token subscribed to this order\'s channel only',
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
                description: 'Token, channel and where to connect',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['channel', 'token', 'port', 'path'],
                            properties: [
                                new OA\Property(
                                    property: 'channel',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'token',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'port',
                                    type: 'integer',
                                ),
                                new OA\Property(
                                    property: 'path',
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
        $channel = OrderChannel::forOrder(orderId: $order->orderId);

        return $this->responseFactory->success(data: new RealtimeTokenResult(
            channel: $channel,
            token: $this->tokens->issue(userId: OrderSource::SHOP_CUSTOMER_ID, channels: [$channel]),
            port: Environment::int(name: 'CENTRIFUGO_PUBLIC_PORT', default: 8000),
            path: '/connection/websocket',
        ));
    }
}
