<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowCaptcha\Data;

/**
 * Whether the checkout asks for a reCAPTCHA answer, and the public site key;
 * the secret is never part of it.
 */
final readonly class CaptchaResult implements \JsonSerializable
{
    public function __construct(
        public bool $enabled,
        public ?string $siteKey,
    ) {}

    /**
     * @return array{enabled: bool, siteKey: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['enabled' => $this->enabled, 'siteKey' => $this->siteKey];
    }
}
