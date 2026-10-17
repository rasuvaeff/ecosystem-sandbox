<?php

declare(strict_types=1);

namespace App\Application\Analytics\GetAnalyticsReport;

enum ResultStoreAvailability
{
    /** Plain `make ui` runs no ClickHouse at all. */
    case Unreachable;

    /** Reachable, but no result has been exported yet. */
    case Empty;
    case Ready;
}
