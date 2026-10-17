<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger;

use OpenApi\Attributes as OA;

/**
 * GET /files/{token} is yii3-filestorage-web's FileDownloadAction, a vendor
 * class the scan never sees; its operation is documented here so the
 * published contract covers every route the application serves.
 */
#[OA\Get(
    path: '/files/{token}',
    operationId: 'files.download',
    summary: 'A stored file behind a signed, expiring token (yii3-filestorage-web)',
    parameters: [
        new OA\Parameter(
            name: 'token',
            in: 'path',
            required: true,
            schema: new OA\Schema(
                type: 'string',
            ),
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'The file',
            content: [
                new OA\MediaType(
                    mediaType: 'text/plain',
                    schema: new OA\Schema(
                        type: 'string',
                    ),
                ),
            ],
        ),
        new OA\Response(
            response: 304,
            description: 'Not modified (conditional GET)',
        ),
        new OA\Response(
            response: 404,
            description: 'Any failure (bad signature, expiry, unknown file); a 403 would confirm that the id exists',
        ),
    ],
)]
final readonly class FileDownloadOperation {}
