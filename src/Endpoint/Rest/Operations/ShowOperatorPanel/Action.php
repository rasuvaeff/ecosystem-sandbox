<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowOperatorPanel;

use App\Application\Operations\GetOperatorPanel\GetOperatorPanelHandler;
use App\Endpoint\Rest\Operations\ShowOperatorPanel\Data\OperatorViewResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private GetOperatorPanelHandler $panel,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/operator',
        operationId: 'operator.show',
        summary: 'Feature flags, maintenance, runtime settings and the audit masker, read-only',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operator view; never contains secrets or the maintenance bypass hash',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/OperatorView',
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(): ResponseInterface
    {
        return $this->responseFactory->success(data: new OperatorViewResult(panel: $this->panel->handle()));
    }
}
