<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3ApiProblem\ProblemDetailsMiddleware;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3MaintenanceMode\MaintenanceMiddleware;
use Rasuvaeff\Yii3Metrics\RedMetricsMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3Tenancy\TenantResolutionMiddleware;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\JsonRenderer;
use Yiisoft\ErrorHandler\ThrowableRendererInterface;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Yii\Http\Application;

return [
    ErrorHandler::class => static fn (LoggerInterface $logger): ErrorHandler => new ErrorHandler(
        logger: $logger,
        defaultRenderer: new JsonRenderer(),
    ),
    ThrowableRendererInterface::class => JsonRenderer::class,
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                // Order is load-bearing:
                //   - correlation is outermost, because a response built ABOVE
                //     it never gets the X-Request-ID header and a log record
                //     written above it is filed under no id at all. With
                //     ProblemDetails outside, a 400 problem response came back
                //     without the header while a 404 from the action had it;
                //   - observability sits outside the problem renderer, so it
                //     sees the rendered status: actions throw their 404/409/503
                //     problems, and with ProblemDetails outside, RED metrics
                //     counted every one as a 500 and the span lost its status;
                //   - then the error boundary, so every failure below renders as
                //     RFC 9457 instead of an HTML 500, inside the correlation
                //     scope the ErrorHandler's logger reads;
                //   - observability wraps maintenance on purpose: a maintenance
                //     503 is an operational signal and must stay visible in RED
                //     metrics and traces;
                //   - maintenance precedes idempotency so that no claim is
                //     written to storage while the application is down. That is
                //     a precaution, not a provable invariant: yii3-idempotency
                //     caches only 2xx responses, so a 503 releases the claim in
                //     either order - swapping these two leaves
                //     `make simulate-maintenance` green.
                'withMiddlewares()' => [[
                    CorrelationIdMiddleware::class,
                    ErrorCatcher::class,
                    OtelMiddleware::class,
                    RedMetricsMiddleware::class,
                    ProblemDetailsMiddleware::class,
                    MaintenanceMiddleware::class,
                    IdempotencyMiddleware::class,
                    TenantResolutionMiddleware::class,
                    Router::class,
                    // Reached only when no route matched.
                    App\Infrastructure\Shared\Http\RouteNotFound::class,
                ]],
            ]),
        ],
    ],
];
