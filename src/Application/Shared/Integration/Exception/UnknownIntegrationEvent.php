<?php

declare(strict_types=1);

namespace App\Application\Shared\Integration\Exception;

final class UnknownIntegrationEvent extends \RuntimeException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(message: 'No integration event is registered for type "' . $type . '"');
    }
}
