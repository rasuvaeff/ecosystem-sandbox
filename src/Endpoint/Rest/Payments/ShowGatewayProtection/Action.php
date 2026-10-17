<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Payments\ShowGatewayProtection;

use App\Application\Payments\GetGatewayProtection\GetGatewayProtectionHandler;
use App\Endpoint\Rest\Payments\ShowGatewayProtection\Data\ResilienceStateResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private GetGatewayProtectionHandler $protection,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/resilience/payment-gateway',
        operationId: 'resilience.paymentGateway',
        summary: 'Live circuit breaker and bulkhead state of the payment gateway, read from Redis',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Breaker and bulkhead state',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/ResilienceState',
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(): ResponseInterface
    {
        return $this->responseFactory->success(data: ResilienceStateResult::fromArray(state: $this->protection->handle()));
    }
}
