<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Analytics\ShowReport\Data;

/**
 * Payment results as ClickHouse holds them, the campaigns and the A/B test.
 * The report is assembled field by field by GetAnalyticsReportHandler; the
 * endpoint passes it on as it is.
 */
final readonly class AnalyticsReportResult implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $report
     */
    public function __construct(private array $report) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->report;
    }
}
