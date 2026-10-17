<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Http;

use App\Shared\Http\Problem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Recaptcha\RecaptchaClient;

/**
 * Opt-in reCAPTCHA v2 on the checkout (CAPTCHA_ENABLED). Off by default: the
 * check calls Google, and every scripted scenario posts checkouts without a
 * browser. When on, the token comes in X-Captcha-Token (the API is headless:
 * the shop page renders the widget and forwards the answer), and anything that
 * does not verify — a missing token, a wrong one, Google unreachable — is a
 * 403 before the checkout runs: RecaptchaClient fails closed.
 */
final readonly class CaptchaGate implements MiddlewareInterface
{
    public const string HEADER = 'X-Captcha-Token';

    public function __construct(
        private RecaptchaClient $recaptcha,
        private bool $enabled,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->enabled) {
            $token = $request->getHeaderLine(self::HEADER);
            if ($token === '' || !$this->recaptcha->verify(token: $token)->success) {
                throw Problem::captchaFailed();
            }
        }

        return $handler->handle($request);
    }
}
