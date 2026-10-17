<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ui\ShowTestReport\Data;

use App\Application\Operations\GetTestReport\TestReport;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'TestReport', required: ['unit', 'property', 'mutation'], properties: [
    new OA\Property(
        property: 'unit',
        oneOf: [
            new OA\Schema(
                type: 'null',
            ),
            new OA\Schema(
                ref: '#/components/schemas/TestSuiteReport',
            ),
        ],
    ),
    new OA\Property(
        property: 'property',
        oneOf: [
            new OA\Schema(
                type: 'null',
            ),
            new OA\Schema(
                ref: '#/components/schemas/TestSuiteReport',
            ),
        ],
    ),
    new OA\Property(
        property: 'mutation',
        oneOf: [
            new OA\Schema(
                type: 'null',
            ),
            new OA\Schema(
                ref: '#/components/schemas/MutationReport',
            ),
        ],
    ),
], type: 'object', additionalProperties: false)]
#[OA\Schema(schema: 'TestSuiteReport', required: ['name', 'ranAt', 'tests', 'failures', 'seconds', 'classes'], properties: [
    new OA\Property(
        property: 'name',
        type: 'string',
    ),
    new OA\Property(
        property: 'ranAt',
        type: 'string',
        format: 'date-time',
    ),
    new OA\Property(
        property: 'tests',
        type: 'integer',
        minimum: 0,
    ),
    new OA\Property(
        property: 'failures',
        type: 'integer',
        minimum: 0,
    ),
    new OA\Property(
        property: 'seconds',
        type: 'number',
        minimum: 0,
    ),
    new OA\Property(
        property: 'classes',
        type: 'array',
        items: new OA\Items(
            required: ['name', 'layer', 'cases'],
            properties: [
                new OA\Property(
                    property: 'name',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'layer',
                    type: 'string',
                    enum: ['Domain', 'Application', 'Infrastructure', 'Architecture', 'Other'],
                ),
                new OA\Property(
                    property: 'cases',
                    type: 'array',
                    items: new OA\Items(
                        required: ['name', 'status', 'distribution'],
                        properties: [
                            new OA\Property(
                                property: 'name',
                                type: 'string',
                            ),
                            new OA\Property(
                                property: 'status',
                                type: 'string',
                                enum: ['passed', 'failed', 'skipped'],
                            ),
                            new OA\Property(
                                property: 'distribution',
                                description: 'How a property test\'s generated cases fell across the branches it classifies',
                                type: 'array',
                                items: new OA\Items(
                                    required: ['label', 'hits', 'runs'],
                                    properties: [
                                        new OA\Property(
                                            property: 'label',
                                            type: 'string',
                                        ),
                                        new OA\Property(
                                            property: 'hits',
                                            type: 'integer',
                                            minimum: 0,
                                        ),
                                        new OA\Property(
                                            property: 'runs',
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
            type: 'object',
            additionalProperties: false,
        ),
    ),
], type: 'object', additionalProperties: false)]
#[OA\Schema(schema: 'MutationReport', required: ['ranAt', 'total', 'caught', 'missed', 'msi', 'minMsi', 'files', 'examples', 'trend'], properties: [
    new OA\Property(
        property: 'ranAt',
        type: 'string',
        format: 'date-time',
    ),
    new OA\Property(
        property: 'total',
        type: 'integer',
        minimum: 0,
    ),
    new OA\Property(
        property: 'caught',
        type: 'integer',
        minimum: 0,
    ),
    new OA\Property(
        property: 'missed',
        type: 'integer',
        minimum: 0,
    ),
    new OA\Property(
        property: 'msi',
        type: 'number',
        maximum: 100,
        minimum: 0,
    ),
    new OA\Property(
        property: 'minMsi',
        description: 'The floor the mutation gate holds a run to; null when infection.json5 is not readable',
        type: ['number', 'null'],
    ),
    new OA\Property(
        property: 'files',
        type: 'array',
        items: new OA\Items(
            required: ['file', 'caught', 'missed'],
            properties: [
                new OA\Property(
                    property: 'file',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'caught',
                    type: 'integer',
                    minimum: 0,
                ),
                new OA\Property(
                    property: 'missed',
                    type: 'integer',
                    minimum: 0,
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'examples',
        type: 'array',
        items: new OA\Items(
            required: ['file', 'line', 'mutator', 'diff', 'caughtBy'],
            properties: [
                new OA\Property(
                    property: 'file',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'line',
                    type: 'integer',
                ),
                new OA\Property(
                    property: 'mutator',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'diff',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'caughtBy',
                    type: 'array',
                    items: new OA\Items(
                        type: 'string',
                    ),
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
    new OA\Property(
        property: 'trend',
        type: 'array',
        items: new OA\Items(
            required: ['run', 'msi'],
            properties: [
                new OA\Property(
                    property: 'run',
                    type: 'string',
                ),
                new OA\Property(
                    property: 'msi',
                    type: 'number',
                ),
            ],
            type: 'object',
            additionalProperties: false,
        ),
    ),
], type: 'object', additionalProperties: false)]
final readonly class TestReportResult implements \JsonSerializable
{
    public function __construct(private TestReport $report) {}

    public function jsonSerialize(): TestReport
    {
        return $this->report;
    }
}
