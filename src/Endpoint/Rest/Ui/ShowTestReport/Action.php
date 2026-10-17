<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ui\ShowTestReport;

use App\Application\Operations\GetTestReport\GetTestReportHandler;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Endpoint\Rest\Ui\ShowTestReport\Data\TestReportResult;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * The tests page in one request: the last unit and property runs and the
 * last mutation gate, as the make targets left them under build/.
 */
final readonly class Action
{
    public function __construct(
        private GetTestReportHandler $report,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/ui/tests',
        operationId: 'ui.tests',
        summary: 'The last test runs: the unit and property suites and the mutation gate',
        tags: ['UI'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'What the tests page renders; a part is null until its make target has run',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/TestReport',
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(): ResponseInterface
    {
        return $this->responseFactory->success(data: new TestReportResult(report: $this->report->handle()));
    }
}
