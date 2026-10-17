<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Marketing\ShowExperiment;

use App\Application\Marketing\RecordExposure\RecordExposureCommand;
use App\Application\Marketing\RecordExposure\RecordExposureHandler;
use App\Endpoint\Rest\Marketing\ShowExperiment\Data\ExposureResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;

/**
 * The shop page's A/B test: which pay button this visitor sees. The visitor
 * is the `ab_id` cookie SubjectIdMiddleware keeps; StickyAssignmentMiddleware
 * pins the variant. Showing the button is the exposure; the signed receipt
 * comes back with the checkout, which records the conversion against the
 * variant actually seen.
 */
final readonly class Action
{
    public const string EXPERIMENT = 'checkout-button';

    public function __construct(
        private RecordExposureHandler $recordExposure,
        private SignedReceiptCodec $receipts,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(path: '/api/v1/experiments/checkout-button', operationId: 'experiment.checkoutButton', description: 'Sets the ab_id visitor cookie and the signed ab_variants sticky cookie. Every call records an exposure in the outbox.', summary: 'The pay-button variant for this visitor, with the exposure recorded', responses: [
        new OA\Response(
            response: 200,
            description: 'The assigned variant and a signed exposure receipt',
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        required: ['experiment', 'variant', 'reason', 'sticky', 'receipt'],
                        properties: [
                            new OA\Property(
                                property: 'experiment',
                                type: 'string',
                            ),
                            new OA\Property(
                                property: 'variant',
                                type: 'string',
                                enum: ['control', 'green'],
                            ),
                            new OA\Property(
                                property: 'reason',
                                type: 'string',
                                enum: ['assigned', 'forced', 'fallback_disabled', 'fallback_targeting_mismatch'],
                            ),
                            new OA\Property(
                                property: 'sticky',
                                type: 'boolean',
                            ),
                            new OA\Property(
                                property: 'receipt',
                                type: 'string',
                            ),
                        ],
                        type: 'object',
                        additionalProperties: false,
                    ),
                ),
            ],
        ),
    ])]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $subject = new SubjectIdRequestAccessor()->require($request);
        $assignment = new StickyAssignmentRequestAccessor()->resolver($request)->resolve(
            experiment: self::EXPERIMENT,
            subjectId: $subject->value,
        );
        $exposure = $this->recordExposure->handle(command: new RecordExposureCommand(assignment: $assignment));

        return $this->responseFactory->success(
            data: new ExposureResult(
                experiment: self::EXPERIMENT,
                variant: $assignment->variant,
                reason: $assignment->reason->value,
                sticky: $assignment->isSticky(),
                receipt: $this->receipts->encode($exposure->receipt()),
            ),
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
