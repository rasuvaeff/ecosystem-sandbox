<?php

declare(strict_types=1);

namespace App\Application\Analytics\GetAnalyticsReport;

use App\Domain\Analytics\OrderSource;

/**
 * The analytics page: payment results from ClickHouse (shop orders, with
 * generated and scripted ones counted apart), the A/B test per variant, and
 * campaigns from PostgreSQL — which work with or without ClickHouse. A
 * reachable store that cannot answer is an error the page shows, never an
 * empty report passed off as one.
 */
final readonly class GetAnalyticsReportHandler
{
    public const int LATEST = 10;

    public function __construct(
        private OrderResultStore $results,
        private ExperimentResults $experiment,
        private CampaignResults $campaigns,
        private DashboardStatus $dashboard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $experiment): array
    {
        $report = [
            'available' => false,
            'grafana' => $this->dashboard->isUp(),
            'error' => null,
            'totals' => [],
            'recent' => [],
            'excluded' => ['orders' => 0, 'amountCents' => 0],
            'campaigns' => $this->campaigns->rows(),
            'experiment' => ['name' => $experiment, 'pending' => $this->experiment->pendingEvents(), 'variants' => []],
        ];
        $availability = $this->results->availability();
        if ($availability === ResultStoreAvailability::Unreachable) {
            return $report;
        }
        $report['available'] = true;

        try {
            $report['experiment']['variants'] = $this->experiment->variants(experiment: $experiment);
        } catch (\Throwable $exception) {
            $report['error'] = $exception->getMessage();
        }
        if ($availability === ResultStoreAvailability::Ready) {
            try {
                $report['totals'] = $this->results->totalsByStatus(source: OrderSource::Shop);
                $report['recent'] = $this->results->latest(source: OrderSource::Shop, limit: self::LATEST);
                $report['excluded'] = $this->results->summary(source: OrderSource::Other);
            } catch (\Throwable $exception) {
                $report['error'] = $exception->getMessage();
            }
        }

        return $report;
    }
}
