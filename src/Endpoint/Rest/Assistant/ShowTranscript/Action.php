<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Assistant\ShowTranscript;

use App\Application\Assistant\GetTranscript\GetTranscriptHandler;
use App\Endpoint\Rest\Assistant\ShowTranscript\Data\TranscriptResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private GetTranscriptHandler $transcript,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/agent/transcript',
        operationId: 'agent.transcript',
        summary: 'An in-process MCP conversation about one order, as a permitted and an unpermitted agent',
        parameters: [
            new OA\Parameter(
                name: 'orderId',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    maxLength: 64,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The conversation of each identity',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['orderId', 'identities'],
                            properties: [
                                new OA\Property(
                                    property: 'orderId',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'identities',
                                    type: 'array',
                                    items: new OA\Items(
                                        required: ['user', 'permitted', 'steps', 'audit'],
                                        properties: [
                                            new OA\Property(
                                                property: 'user',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'permitted',
                                                type: 'boolean',
                                            ),
                                            new OA\Property(
                                                property: 'steps',
                                                type: 'array',
                                                items: new OA\Items(
                                                    required: ['call', 'arguments', 'refused', 'error', 'result'],
                                                    properties: [
                                                        new OA\Property(
                                                            property: 'call',
                                                            type: 'string',
                                                        ),
                                                        new OA\Property(
                                                            property: 'arguments',
                                                        ),
                                                        new OA\Property(
                                                            property: 'refused',
                                                            type: 'boolean',
                                                        ),
                                                        new OA\Property(
                                                            property: 'error',
                                                            type: 'boolean',
                                                        ),
                                                        new OA\Property(
                                                            property: 'result',
                                                        ),
                                                    ],
                                                    type: 'object',
                                                    additionalProperties: false,
                                                ),
                                            ),
                                            new OA\Property(
                                                property: 'audit',
                                                description: 'The audit rows yii3-mcp-audit-log-bridge wrote for these calls',
                                                type: 'array',
                                                items: new OA\Items(
                                                    required: ['id', 'tool', 'outcome', 'durationMs', 'at', 'arguments'],
                                                    properties: [
                                                        new OA\Property(
                                                            property: 'id',
                                                            type: 'string',
                                                        ),
                                                        new OA\Property(
                                                            property: 'tool',
                                                            type: 'string',
                                                        ),
                                                        new OA\Property(
                                                            property: 'outcome',
                                                            type: 'string',
                                                            enum: ['success', 'rejected', 'error'],
                                                        ),
                                                        new OA\Property(
                                                            property: 'durationMs',
                                                            type: 'integer',
                                                            minimum: 0,
                                                        ),
                                                        new OA\Property(
                                                            property: 'at',
                                                            type: 'string',
                                                        ),
                                                        new OA\Property(
                                                            property: 'arguments',
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
                            type: 'object',
                            additionalProperties: false,
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(Input $input): ResponseInterface
    {
        return $this->responseFactory->success(data: new TranscriptResult(orderId: $input->orderId(), identities: $this->transcript->handle(query: $input->createQuery())));
    }
}
