<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Rasuvaeff\Yii3ApiProblem\ProblemDetails;
use Rasuvaeff\Yii3ApiProblem\ProblemDetailsException;

/**
 * The sandbox's RFC 9457 problems, thrown from actions and rendered by
 * ProblemDetailsMiddleware: one place owns the type URIs and titles.
 */
final readonly class Problem
{
    private const string TYPE_BASE = 'https://sandbox.invalid/problems/';

    public static function orderNotFound(string $detail = 'The requested order does not exist'): ProblemDetailsException
    {
        return self::create(
            status: 404,
            slug: 'order-not-found',
            title: 'Order not found',
            detail: $detail,
        );
    }

    public static function orderPayloadConflict(): ProblemDetailsException
    {
        return self::create(
            status: 409,
            slug: 'order-payload-conflict',
            title: 'Order payload conflict',
            detail: 'The order already exists with a different customer or amount',
        );
    }

    public static function orderAlreadySettled(string $status): ProblemDetailsException
    {
        return self::create(
            status: 409,
            slug: 'order-already-settled',
            title: 'Order already settled',
            detail: 'The order has already reached the status "' . $status . '" and cannot be paid again',
        );
    }

    public static function paymentsDisabled(): ProblemDetailsException
    {
        return self::create(
            status: 503,
            slug: 'payments-disabled',
            title: 'Payments temporarily disabled',
            detail: 'Checkout is disabled by the payments kill switch',
        );
    }

    public static function captchaFailed(): ProblemDetailsException
    {
        return self::create(
            status: 403,
            slug: 'captcha-failed',
            title: 'Captcha check failed',
            detail: 'The X-Captcha-Token header is missing or did not verify',
        );
    }

    public static function routeNotFound(): ProblemDetailsException
    {
        return self::create(
            status: 404,
            slug: 'route-not-found',
            title: 'Not found',
            detail: 'No route matches this URL',
        );
    }

    public static function pageNotFound(): ProblemDetailsException
    {
        return self::create(
            status: 404,
            slug: 'page-not-found',
            title: 'Page not found',
            detail: 'The web UI has no such page',
        );
    }

    public static function tenantNotFound(): ProblemDetailsException
    {
        return self::create(
            status: 404,
            slug: 'tenant-not-found',
            title: 'Tenant not found',
            detail: 'No tenant matches the X-Tenant-Id header',
        );
    }

    private static function create(int $status, string $slug, string $title, string $detail): ProblemDetailsException
    {
        return ProblemDetailsException::forProblem(ProblemDetails::create(
            title: $title,
            status: $status,
            type: self::TYPE_BASE . $slug,
            detail: $detail,
        ));
    }
}
