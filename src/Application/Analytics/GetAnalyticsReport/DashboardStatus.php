<?php

declare(strict_types=1);

namespace App\Application\Analytics\GetAnalyticsReport;

interface DashboardStatus
{
    public function isUp(): bool;
}
