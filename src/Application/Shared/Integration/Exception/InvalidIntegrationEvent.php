<?php

declare(strict_types=1);

namespace App\Application\Shared\Integration\Exception;

/**
 * A payload that does not satisfy its event's schema. The message names the
 * type only: a payload is untrusted data and never echoed.
 */
final class InvalidIntegrationEvent extends \RuntimeException
{
    public function __construct(public readonly string $type, ?\Throwable $previous = null)
    {
        parent::__construct(message: 'The payload of "' . $type . '" does not match its schema', previous: $previous);
    }
}
