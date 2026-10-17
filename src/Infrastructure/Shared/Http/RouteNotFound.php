<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Http;

use App\Shared\Http\Problem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The last middleware, after the router: a request no route matched reaches
 * it and becomes a 404 problem. Without it the application's fallback
 * handler answered every unknown URL with a 500.
 */
final readonly class RouteNotFound implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw Problem::routeNotFound();
    }
}
