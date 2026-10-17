<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3ApiProblem\ExceptionMapperInterface;
use Rasuvaeff\Yii3ApiProblem\ProblemDetailsException;
use Rasuvaeff\Yii3ApiProblem\ThrowableReporterInterface;

/**
 * ProblemDetailsMiddleware catches every exception below it, so the
 * ErrorCatcher outside never sees one: without a reporter, a 500 left no trace
 * in the logs at all. A failure that renders as a 5xx is logged with its
 * exception; a problem the application meant to send (404, 409, 503 from an
 * action, a mapped 400) is an answer, not an incident, and is not.
 */
final readonly class ServerErrorReporter implements ThrowableReporterInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ?ExceptionMapperInterface $mapper,
    ) {}

    public function report(\Throwable $throwable, ServerRequestInterface $request): void
    {
        if ($throwable instanceof ProblemDetailsException || ($this->mapper?->toProblem($throwable)?->status ?? 500) < 500) {
            return;
        }

        try {
            $this->logger->error('Unhandled exception while serving {method} {path}', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'exception' => $throwable,
            ]);
        } catch (\Throwable) {
            // The reporter must not throw: the client still gets its 500.
        }
    }
}
