<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Http;

use App\Application\Marketing\CheckoutTouchpoints;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec;
use Rasuvaeff\Yii3Utm\UtmRequest;

/**
 * Route-level on the checkout, after UtmCaptureMiddleware: hands the
 * campaign touch (X-Utm-* headers) and the A/B receipt (X-Ab-Receipt) to
 * Marketing for this request. The receipt is signed, so a client cannot claim
 * the other variant; a forged or absent one is simply no conversion.
 */
final readonly class RememberCheckoutTouchpoints implements MiddlewareInterface
{
    public const string RECEIPT_HEADER = 'X-Ab-Receipt';

    public function __construct(
        private CheckoutTouchpoints $touchpoints,
        private SignedReceiptCodec $receipts,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->touchpoints->remember(
            touchpoint: UtmRequest::effective($request),
            receipt: $request->hasHeader(self::RECEIPT_HEADER) ? $this->receipts->decode($request->getHeaderLine(self::RECEIPT_HEADER)) : null,
        );

        return $handler->handle($request);
    }
}
