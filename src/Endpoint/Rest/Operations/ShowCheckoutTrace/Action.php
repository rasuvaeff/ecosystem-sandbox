<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowCheckoutTrace;

use App\Application\Operations\GetCheckoutTrace\GetCheckoutTraceHandler;
use App\Application\Operations\GetCheckoutTrace\GetCheckoutTraceQuery;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Endpoint\Rest\Operations\ShowCheckoutTrace\Data\CheckoutTraceResult;
use App\Endpoint\Rest\Operations\ShowCheckoutTrace\Data\TraceBudgetResult;
use App\Endpoint\Rest\Ordering\Shared\OrderIdFromRoute;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Http\Header;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Context\Context;
use Yiisoft\Router\CurrentRoute;

/**
 * The checkout's trace as Jaeger holds it, under the request budget
 * RequestBudget opened; the budget is reported back with the spans.
 */
final readonly class Action
{
    public function __construct(
        private GetCheckoutTraceHandler $trace,
        private ResponseFactory $responseFactory,
        private CurrentRoute $currentRoute,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders/{id}/trace',
        operationId: 'orders.trace',
        summary: 'The checkout\'s trace as Jaeger holds it, with whitelisted span attributes',
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
            new OA\Parameter(
                name: 'X-Request-Timeout-Ms',
                description: 'Shortens the request budget (default 5000 ms, never extended); anything that is not 1-999999999 is ignored',
                in: 'header',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    maxLength: 9,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Spans of the trace; empty while Jaeger has not received them yet',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['orderId', 'traceId', 'spans', 'budget'],
                            properties: [
                                new OA\Property(
                                    property: 'orderId',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'traceId',
                                    type: ['string', 'null'],
                                ),
                                new OA\Property(
                                    property: 'budget',
                                    description: 'The request budget this call ran under (rasuvaeff/context-http)',
                                    required: ['header', 'limitMs', 'remainingMs'],
                                    properties: [
                                        new OA\Property(
                                            property: 'header',
                                            type: 'string',
                                        ),
                                        new OA\Property(
                                            property: 'limitMs',
                                            type: ['integer', 'null'],
                                        ),
                                        new OA\Property(
                                            property: 'remainingMs',
                                            type: ['integer', 'null'],
                                        ),
                                    ],
                                    type: 'object',
                                    additionalProperties: false,
                                ),
                                new OA\Property(
                                    property: 'spans',
                                    type: 'array',
                                    items: new OA\Items(
                                        required: ['name', 'service', 'kind', 'startMs', 'durationMs', 'depth', 'attributes'],
                                        properties: [
                                            new OA\Property(
                                                property: 'name',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'service',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'kind',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'startMs',
                                                type: 'number',
                                            ),
                                            new OA\Property(
                                                property: 'durationMs',
                                                type: 'number',
                                            ),
                                            new OA\Property(
                                                property: 'depth',
                                                type: 'integer',
                                            ),
                                            new OA\Property(
                                                property: 'attributes',
                                                type: 'object',
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
            new OA\Response(
                response: 504,
                description: 'The request budget ran out before the Jaeger call could start; empty body',
            ),
        ],
    )]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $budget = $this->budgetOf(attribute: $request->getAttribute(Context::class));
        $limitMs = $budget->remainingMs();
        $orderId = OrderIdFromRoute::of(route: $this->currentRoute);

        try {
            $trace = $this->trace->handle(query: new GetCheckoutTraceQuery(orderId: $orderId, budget: $budget));
        } catch (OrderNotFoundException) {
            throw Problem::orderNotFound(detail: 'No checkout was recorded for this order');
        }
        $left = $budget->remainingMs();

        return $this->responseFactory->success(data: new CheckoutTraceResult(
            orderId: $orderId->value,
            traceId: $trace['traceId'],
            spans: $trace['spans'],
            budget: new TraceBudgetResult(
                header: Header::REQUEST_BUDGET,
                limitMs: $limitMs === null ? null : (int) round(num: $limitMs),
                remainingMs: $left === null ? null : (int) round(num: $left),
            ),
        ));
    }

    private function budgetOf(mixed $attribute): Context
    {
        return $attribute instanceof Context ? $attribute : Context::background();
    }
}
