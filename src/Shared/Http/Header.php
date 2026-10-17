<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Header names more than one layer has to agree on.
 */
final class Header
{
    /** The caller's request budget in milliseconds (rasuvaeff/context-http). */
    public const string REQUEST_BUDGET = 'X-Request-Timeout-Ms';
}
