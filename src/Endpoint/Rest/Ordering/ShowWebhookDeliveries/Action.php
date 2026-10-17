<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowWebhookDeliveries;

use App\Application\Ordering\GetWebhookDeliveries\GetWebhookDeliveriesHandler;
use App\Endpoint\Rest\Ordering\ShowWebhookDeliveries\Data\WebhookDeliveriesResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private GetWebhookDeliveriesHandler $deliveries,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/webhooks',
        operationId: 'webhooks.list',
        summary: 'Webhook delivery attempts as the sender recorded them, and the receiver\'s journal',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Both ends of the webhook; received is null when the receiver is down',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['deliveries', 'received'],
                            properties: [
                                new OA\Property(
                                    property: 'deliveries',
                                    type: 'array',
                                    items: new OA\Items(
                                        required: ['eventId', 'type', 'endpoint', 'status', 'attempts', 'lastError', 'createdAt'],
                                        properties: [
                                            new OA\Property(
                                                property: 'eventId',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'type',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'endpoint',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'status',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'attempts',
                                                type: 'integer',
                                            ),
                                            new OA\Property(
                                                property: 'lastError',
                                                type: ['string', 'null'],
                                            ),
                                            new OA\Property(
                                                property: 'createdAt',
                                                type: 'string',
                                            ),
                                        ],
                                        type: 'object',
                                        additionalProperties: false,
                                    ),
                                ),
                                new OA\Property(
                                    property: 'received',
                                    type: ['array', 'null'],
                                    items: new OA\Items(
                                        type: 'object',
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
        $deliveries = $this->deliveries->handle();

        return $this->responseFactory->success(data: new WebhookDeliveriesResult(deliveries: $deliveries['deliveries'], received: $deliveries['received']));
    }
}
