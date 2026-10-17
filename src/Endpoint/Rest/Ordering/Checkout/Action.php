<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Checkout;

use App\Application\Ordering\PlaceOrder\PlaceOrderHandler;
use App\Application\Ordering\PlaceOrder\PlaceOrderResult;
use App\Domain\Ordering\Refusal\AlreadySettled;
use App\Domain\Ordering\Refusal\PayloadConflict;
use App\Endpoint\Rest\Ordering\Checkout\Data\CheckoutAcceptedResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Http\Problem;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\Yii3FeatureFlags\FeatureFlags;
use Yiisoft\Http\Status;

final readonly class Action
{
    public function __construct(
        private PlaceOrderHandler $placeOrder,
        private FeatureFlags $featureFlags,
        private Contract $contract,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Post(path: '/api/v1/checkout', operationId: 'checkout.create', summary: 'Accept an order for asynchronous payment', requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    ref: '#/components/schemas/CheckoutRequest',
                ),
            ),
        ],
    ), parameters: [
        new OA\Parameter(
            name: 'Idempotency-Key',
            description: 'Letters, digits, dot, underscore and hyphen — the format yii3-idempotency accepts',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 128,
                minLength: 1,
                pattern: '^[A-Za-z0-9._-]+$',
            ),
        ),
        new OA\Parameter(
            name: 'X-Captcha-Token',
            description: 'The reCAPTCHA v2 answer; required only while CAPTCHA_ENABLED is on (see GET /api/v1/captcha)',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 4096,
            ),
        ),
        new OA\Parameter(
            name: 'X-Ab-Receipt',
            description: 'Signed exposure receipt from GET /api/v1/experiments/checkout-button; a valid one records the A/B conversion with the order, a forged one is ignored',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 2048,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Source',
            description: 'Campaign source (yii3-utm HeaderUtmSource); untrusted, normalised and truncated by the server',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 255,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Medium',
            description: 'Campaign medium',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 255,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Campaign',
            description: 'Campaign name',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 255,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Term',
            description: 'Campaign term',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 255,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Content',
            description: 'Campaign content',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 255,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Id',
            description: 'Campaign id',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 255,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Click-Ids',
            description: 'JSON object of advertising click ids (gclid, fbclid, ...); invalid JSON is ignored',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 500,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Landing-Page',
            description: 'Page the visitor landed on; the server keeps only scheme, host, path and campaign parameters',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 2000,
            ),
        ),
        new OA\Parameter(
            name: 'X-Utm-Referrer',
            description: 'Referring page',
            in: 'header',
            required: false,
            schema: new OA\Schema(
                type: 'string',
                maxLength: 2000,
            ),
        ),
    ], responses: [
        new OA\Response(
            response: 202,
            description: 'Checkout accepted and payment request placed in the outbox',
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/CheckoutAccepted',
                    ),
                ),
            ],
        ),
        new OA\Response(
            response: 400,
            description: 'Invalid JSON or payload (problem+json from the application), or a malformed Idempotency-Key (plain JSON from yii3-idempotency)',
            content: [
                new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/Problem',
                    ),
                ),
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/IdempotencyError',
                    ),
                ),
            ],
        ),
        new OA\Response(
            response: 403,
            description: 'The captcha gate is on and X-Captcha-Token is missing or did not verify',
            content: [
                new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/Problem',
                    ),
                ),
            ],
        ),
        new OA\Response(
            response: 409,
            description: 'The order id already exists with a different customer or amount, or the order has already settled as paid or failed (problem+json); or a request with the same Idempotency-Key is still being processed (plain JSON from yii3-idempotency)',
            content: [
                new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/Problem',
                    ),
                ),
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/IdempotencyError',
                    ),
                ),
            ],
        ),
        new OA\Response(
            response: 422,
            description: 'The Idempotency-Key was already used with a different request body',
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/IdempotencyError',
                    ),
                ),
            ],
        ),
        new OA\Response(
            response: 503,
            description: 'Maintenance mode or the payments kill switch is on',
            headers: [
                new OA\Header(
                    header: 'Retry-After',
                    required: false,
                    schema: new OA\Schema(
                        type: 'string',
                    ),
                ),
            ],
            content: [
                new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(
                        ref: '#/components/schemas/Problem',
                    ),
                ),
            ],
        ),
    ])]
    public function __invoke(ServerRequestInterface $request, Input $input): ResponseInterface
    {
        if (!$this->featureFlags->isEnabled(flag: 'payments.enabled')) {
            throw Problem::paymentsDisabled();
        }

        // The OpenAPI document, generated from this action's attribute, is the
        // one statement of what a request may look like (media type, header
        // lengths and patterns, body shape, malformed JSON included); the
        // request is held to it first, so the action never carries a second
        // copy of a rule.
        try {
            $this->contract->validateRequest($request)->assertValid();
        } catch (ContractViolation $violation) {
            throw new \InvalidArgumentException(message: $violation->getMessage(), previous: $violation);
        }
        $validation = $input->getValidationResult();
        if (!$validation->isValid()) {
            throw new \InvalidArgumentException(message: implode(separator: '; ', array: $validation->getErrorMessages()));
        }
        $key = $request->getHeaderLine('Idempotency-Key');

        $placed = $this->placeOrder->handle(command: $input->createCommand(
            idempotencyKey: $key !== '' ? $key : 'checkout-' . $input->orderId,
        ));

        // The domain's refusals are values; each one is answered with its
        // own problem.
        return $placed->match(
            ok: fn (PlaceOrderResult $result): ResponseInterface => $this->responseFactory->success(
                data: new CheckoutAcceptedResult(orderId: $result->orderId->value, status: $result->status->value, accepted: $result->accepted),
                status: Status::ACCEPTED,
            ),
            err: static fn (PayloadConflict|AlreadySettled $refusal): never => throw match (true) {
                $refusal instanceof PayloadConflict => Problem::orderPayloadConflict(),
                $refusal instanceof AlreadySettled => Problem::orderAlreadySettled(status: $refusal->status->value),
            },
        );
    }
}
