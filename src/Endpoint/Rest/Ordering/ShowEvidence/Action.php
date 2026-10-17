<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowEvidence;

use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Endpoint\Rest\Ordering\Shared\OrderEvidenceComposer;
use App\Endpoint\Rest\Ordering\Shared\OrderIdFromRoute;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * The order page's single source, composed at the edge from three contexts:
 * Ordering (the order, its transitions, audit, outbox), Marketing (where the
 * buyer came from) and Payments (the receipt). None of them knows the others.
 */
final readonly class Action
{
    public function __construct(
        private OrderEvidenceComposer $evidence,
        private ResponseFactory $responseFactory,
        private CurrentRoute $currentRoute,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders/{id}/evidence',
        operationId: 'orders.evidence',
        summary: 'Read everything the database holds about one order (the web UI\'s data source)',
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
                description: 'Order row, workflow transitions, audit entries and outbox delivery state',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/OrderEvidence',
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
            return $this->responseFactory->success(data: $this->evidence->compose(orderId: $orderId));
        } catch (OrderNotFoundException) {
            throw Problem::orderNotFound();
        }
    }
}
