<?php

declare(strict_types=1);

namespace App\Application\Analytics\GetAnalyticsReport;

interface ExperimentResults
{
    /**
     * Visitors and converted visitors per variant; only `assigned` decisions
     * count — forced and fallback assignments are not part of the experiment.
     *
     * @return list<array{variant: string, visitors: int, converted: int}>
     */
    public function variants(string $experiment): array;

    /** A/B events still waiting in the outbox for the exporter. */
    public function pendingEvents(): int;
}
