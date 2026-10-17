<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\ClickHouse;

use App\Application\Analytics\ExportAbEvents\AnalyticsSchema;
use Rasuvaeff\ClickHouseToolkit\Aggregate;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\In;

/**
 * The A/B analytics tables of yii3-ab-testing-clickhouse. Creating them is a
 * deployment step the package never takes implicitly; the sandbox takes it
 * before its first export.
 */
final readonly class AbTestingSchema implements AnalyticsSchema
{
    public function __construct(
        private ClickHouseClientFactory $clientFactory,
    ) {}

    public function isInstalled(): bool
    {
        $query = ClickHouseQueryBuilder::create(allowedFields: [])
            ->withMandatoryFilter(filter: new AndX(
                new ClickHouseRawFilter(sql: 'database = currentDatabase()'),
                new In(field: 'name', values: [AnalyticsSchemaV2::EXPOSURES_TABLE, AnalyticsSchemaV2::CONVERSIONS_TABLE]),
            ))
            ->select(table: 'system.tables')
            ->aggregate(Aggregate::count(alias: 'cnt'))
            ->build();
        $rows = $this->clientFactory->create()->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        return (int) ($rows[0]['cnt'] ?? 0) === 2;
    }

    public function install(): void
    {
        if (!$this->isInstalled()) {
            new SchemaMigrations(client: $this->clientFactory->create())->apply();
        }
    }
}
