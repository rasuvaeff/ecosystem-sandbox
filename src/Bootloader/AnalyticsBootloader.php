<?php

declare(strict_types=1);

namespace App\Bootloader;

use App\Application\Analytics\ExportAbEvents\AnalyticsSchema;
use App\Application\Analytics\GetAnalyticsReport\CampaignResults;
use App\Application\Analytics\GetAnalyticsReport\DashboardStatus;
use App\Application\Analytics\GetAnalyticsReport\ExperimentResults;
use App\Application\Analytics\GetAnalyticsReport\OrderResultStore;
use App\Infrastructure\Analytics\Campaign\DbCampaignResults;
use App\Infrastructure\Analytics\ClickHouse\AbTestingSchema;
use App\Infrastructure\Analytics\ClickHouse\ClickHouseExperimentResults;
use App\Infrastructure\Analytics\ClickHouse\ClickHouseOrderResultStore;
use App\Infrastructure\Analytics\Grafana\GrafanaDashboardStatus;
use Yiisoft\Di\ServiceProviderInterface;

final readonly class AnalyticsBootloader implements ServiceProviderInterface
{
    public function getDefinitions(): array
    {
        return [
            OrderResultStore::class => ClickHouseOrderResultStore::class,
            ExperimentResults::class => ClickHouseExperimentResults::class,
            CampaignResults::class => DbCampaignResults::class,
            DashboardStatus::class => GrafanaDashboardStatus::class,
            AnalyticsSchema::class => AbTestingSchema::class,
        ];
    }

    public function getExtensions(): array
    {
        return [];
    }
}
