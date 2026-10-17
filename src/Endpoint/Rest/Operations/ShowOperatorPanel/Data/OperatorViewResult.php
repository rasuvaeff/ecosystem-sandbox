<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowOperatorPanel\Data;

use OpenApi\Attributes as OA;

/**
 * Feature flags, maintenance, runtime settings and the audit masker,
 * read-only; assembled by GetOperatorPanelHandler, which keeps every secret
 * out of it.
 */
#[OA\Schema(schema: 'OperatorView', required: ['flags', 'maintenance', 'settings', 'auditMasking'], properties: [
    new OA\Property(
        property: 'flags',
        type: 'array',
        items: new OA\Items(
            required: ['name', 'enabled', 'reason'],
            properties: [
                new OA\Property(
                    property: 'name',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'enabled',
                    type: 'boolean',
                ),
                new OA\Property(
                    property: 'reason',
                    type: 'string',
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'maintenance',
        required: ['enabled', 'retryAfterSeconds'],
        properties: [
            new OA\Property(
                property: 'enabled',
                type: 'boolean',
            ),
            new OA\Property(
                property: 'retryAfterSeconds',
                type: 'integer',
            ),
        ],
        type: 'object',
        additionalProperties: false,
    ),
    new OA\Property(
        property: 'settings',
        type: 'array',
        items: new OA\Items(
            required: ['key', 'value', 'secret'],
            properties: [
                new OA\Property(
                    property: 'key',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'value',
                ),
                new OA\Property(
                    property: 'secret',
                    type: 'boolean',
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'auditMasking',
        required: ['sensitiveKeys', 'input', 'output'],
        properties: [
            new OA\Property(
                property: 'sensitiveKeys',
                type: 'array',
                items: new OA\Items(
                    type: 'string',
                ),
            ),
            new OA\Property(
                property: 'input',
                type: 'array',
                items: new OA\Items(
                    ref: '#/components/schemas/AuditChange',
                ),
            ),
            new OA\Property(
                property: 'output',
                type: 'array',
                items: new OA\Items(
                    ref: '#/components/schemas/AuditChange',
                ),
            ),
        ],
        type: 'object',
        additionalProperties: false,
    ),
], type: 'object', additionalProperties: false)]
final readonly class OperatorViewResult implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $panel
     */
    public function __construct(private array $panel) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->panel;
    }
}
