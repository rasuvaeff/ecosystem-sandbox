<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ui\ShowOrderScreen;

use App\Application\Payments\GetGatewayProtection\GetGatewayProtectionHandler;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\Policy\OrderPolicies;
use App\Domain\Shared\Specification\InMemorySpecification;
use App\Endpoint\Rest\Ordering\Shared\OrderEvidenceComposer;
use App\Endpoint\Rest\Ordering\Shared\OrderIdFromRoute;
use App\Endpoint\Rest\Payments\ShowGatewayProtection\Data\ResilienceStateResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Endpoint\Rest\Ui\ShowOrderScreen\Data\OrderScreenResult;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Backend for the order page: everything one refresh of the screen shows, in
 * one answer. The breaker is read only while the order still waits on the
 * bank — a rule the page used to apply itself, now the server's, decided by
 * the domain's own settled policy.
 */
final readonly class Action
{
    public function __construct(
        private OrderEvidenceComposer $evidence,
        private GetGatewayProtectionHandler $protection,
        private ResponseFactory $responseFactory,
        private CurrentRoute $currentRoute,
    ) {}

    #[OA\Get(
        path: '/api/v1/ui/orders/{id}',
        operationId: 'ui.orderScreen',
        summary: 'The order page in one request: the evidence and, while the payment is open, the gateway protection',
        tags: ['UI'],
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
                description: 'What the order page renders',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/OrderScreen',
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
            $evidence = $this->evidence->compose(orderId: $orderId);
        } catch (OrderNotFoundException) {
            throw Problem::orderNotFound();
        }
        $settled = InMemorySpecification::satisfies(specification: OrderPolicies::settled(), row: ['status' => $evidence->order->status]);

        return $this->responseFactory->success(data: new OrderScreenResult(
            evidence: $evidence,
            breaker: $settled ? null : ResilienceStateResult::fromArray(state: $this->protection->handle()),
        ));
    }
}
