<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowCaptcha;

use App\Endpoint\Rest\Operations\ShowCaptcha\Data\CaptchaResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Recaptcha\RecaptchaConfig;

/**
 * What the shop page needs to render the captcha: whether the checkout asks
 * for one, and the public site key. The secret never leaves the server.
 */
final readonly class Action
{
    public function __construct(
        private RecaptchaConfig $config,
        private ResponseFactory $responseFactory,
        private bool $enabled,
    ) {}

    #[OA\Get(
        path: '/api/v1/captcha',
        operationId: 'captcha.show',
        summary: 'Whether the checkout asks for a reCAPTCHA answer, and the public site key',
        responses: [
            new OA\Response(
                response: 200,
                description: 'The captcha gate\'s state; the secret is never part of it',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['enabled', 'siteKey'],
                            properties: [
                                new OA\Property(
                                    property: 'enabled',
                                    type: 'boolean',
                                ),
                                new OA\Property(
                                    property: 'siteKey',
                                    type: ['string', 'null'],
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
        return $this->responseFactory->success(data: new CaptchaResult(enabled: $this->enabled, siteKey: $this->enabled ? $this->config->siteKeyV2 : null));
    }
}
