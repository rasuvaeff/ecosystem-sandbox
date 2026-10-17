<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Analytics\ShowReport;

use App\Application\Analytics\GetAnalyticsReport\GetAnalyticsReportHandler;
use App\Endpoint\Rest\Analytics\ShowReport\Data\AnalyticsReportResult;
use App\Endpoint\Rest\Marketing\ShowExperiment\Action as ShowExperiment;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private GetAnalyticsReportHandler $report,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/analytics',
        operationId: 'analytics.show',
        summary: 'Payment results as ClickHouse holds them; available is false without ClickHouse',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Totals and the latest events',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['available', 'grafana', 'error', 'totals', 'recent', 'excluded', 'campaigns', 'experiment'],
                            properties: [
                                new OA\Property(
                                    property: 'available',
                                    type: 'boolean',
                                ),
                                new OA\Property(
                                    property: 'grafana',
                                    type: 'boolean',
                                ),
                                new OA\Property(
                                    property: 'error',
                                    type: ['string', 'null'],
                                ),
                                new OA\Property(
                                    property: 'totals',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                    ),
                                ),
                                new OA\Property(
                                    property: 'recent',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                    ),
                                ),
                                new OA\Property(
                                    property: 'excluded',
                                    description: 'Orders not placed from the shop page (generated or scripted), left out of totals and recent',
                                    required: ['orders', 'amountCents'],
                                    properties: [
                                        new OA\Property(
                                            property: 'orders',
                                            type: 'integer',
                                            minimum: 0,
                                        ),
                                        new OA\Property(
                                            property: 'amountCents',
                                            type: 'integer',
                                            minimum: 0,
                                        ),
                                    ],
                                    type: 'object',
                                    additionalProperties: false,
                                ),
                                new OA\Property(
                                    property: 'experiment',
                                    description: 'The pay-button A/B test, read from ClickHouse (variants) and the outbox (pending)',
                                    required: ['name', 'pending', 'variants'],
                                    properties: [
                                        new OA\Property(
                                            property: 'name',
                                            type: 'string',
                                        ),
                                        new OA\Property(
                                            property: 'pending',
                                            type: 'integer',
                                            minimum: 0,
                                        ),
                                        new OA\Property(
                                            property: 'variants',
                                            type: 'array',
                                            items: new OA\Items(
                                                required: ['variant', 'visitors', 'converted'],
                                                properties: [
                                                    new OA\Property(
                                                        property: 'variant',
                                                        type: 'string',
                                                    ),
                                                    new OA\Property(
                                                        property: 'visitors',
                                                        type: 'integer',
                                                        minimum: 0,
                                                    ),
                                                    new OA\Property(
                                                        property: 'converted',
                                                        type: 'integer',
                                                        minimum: 0,
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
                                new OA\Property(
                                    property: 'campaigns',
                                    description: 'Shop-page orders by the campaign yii3-utm attributed them to (PostgreSQL)',
                                    type: 'array',
                                    items: new OA\Items(
                                        required: ['channel', 'source', 'medium', 'campaign', 'orders', 'paidOrders', 'paidAmountCents'],
                                        properties: [
                                            new OA\Property(
                                                property: 'channel',
                                                ref: '#/components/schemas/UtmChannel',
                                            ),
                                            new OA\Property(
                                                property: 'source',
                                                type: ['string', 'null'],
                                            ),
                                            new OA\Property(
                                                property: 'medium',
                                                type: ['string', 'null'],
                                            ),
                                            new OA\Property(
                                                property: 'campaign',
                                                type: ['string', 'null'],
                                            ),
                                            new OA\Property(
                                                property: 'orders',
                                                type: 'integer',
                                                minimum: 0,
                                            ),
                                            new OA\Property(
                                                property: 'paidOrders',
                                                type: 'integer',
                                                minimum: 0,
                                            ),
                                            new OA\Property(
                                                property: 'paidAmountCents',
                                                type: 'integer',
                                                minimum: 0,
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
        return $this->responseFactory->success(data: new AnalyticsReportResult(report: $this->report->handle(experiment: ShowExperiment::EXPERIMENT)));
    }
}
