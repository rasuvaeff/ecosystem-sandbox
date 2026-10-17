<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Http;

use App\Shared\Http\Header;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Http\ContextMiddleware;

/**
 * A request budget (rasuvaeff/context-http) on the synchronous endpoints that
 * call another service. The budget is DEFAULT_SECONDS unless the caller asks
 * for less in X-Request-Timeout-Ms (never for more); an expired budget is a
 * 504 before the next call starts.
 *
 * ContextMiddleware takes a correlation id from a header or mints its own.
 * CorrelationIdMiddleware already owns the request's id and trusts no
 * caller's, so the owner's id is put into that header first: one request,
 * one id, whatever the client sent.
 */
final readonly class RequestBudget implements MiddlewareInterface
{
    public const float DEFAULT_SECONDS = 5.0;
    private const string ID_HEADER = 'X-Request-ID';

    private ContextMiddleware $context;

    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        $this->context = new ContextMiddleware(
            timeout: self::DEFAULT_SECONDS,
            correlationHeader: self::ID_HEADER,
            deadlineHeader: Header::REQUEST_BUDGET,
            responseFactory: $responseFactory,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var mixed $id the attribute CorrelationIdMiddleware sets */
        $id = $request->getAttribute('correlationId');

        return $this->context->process(
            is_string($id) && $id !== '' ? $request->withHeader(self::ID_HEADER, $id) : $request->withoutHeader(self::ID_HEADER),
            $handler,
        );
    }
}
