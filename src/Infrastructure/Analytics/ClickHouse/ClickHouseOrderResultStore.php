<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\ClickHouse;

use App\Application\Analytics\GetAnalyticsReport\OrderResultStore;
use App\Application\Analytics\GetAnalyticsReport\ResultStoreAvailability;
use App\Domain\Analytics\OrderSource;
use Rasuvaeff\ClickHouseToolkit\Aggregate;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\Scalar;
use Rasuvaeff\ClickHouseToolkit\SelectQuery;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

/**
 * The results table on ClickHouse, read through clickhouse-toolkit's select
 * builder: identifiers validated, projections from its closed sets of
 * aggregates and scalars, every value (the source filter, LIMIT) bound
 * server-side as {name:Type}.
 */
final class ClickHouseOrderResultStore implements OrderResultStore
{
    private const string TABLE = ExportOrderResultToClickHouse::TABLE;

    private ?ClickHouseClient $client = null;

    public function __construct(private readonly ClickHouseClientFactory $clientFactory) {}

    public function availability(): ResultStoreAvailability
    {
        try {
            // One round trip tells reachability and whether the first
            // exported result has created the table yet.
            $query = ClickHouseQueryBuilder::create(allowedFields: [])
                ->withMandatoryFilter(filter: new AndX(new ClickHouseRawFilter(sql: 'database = currentDatabase()'), new Equals(field: 'name', value: self::TABLE)))
                ->select(table: 'system.tables')
                ->aggregate(Aggregate::count(alias: 'cnt'))
                ->build();
            $rows = $this->client()->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;
        } catch (\Throwable) {
            return ResultStoreAvailability::Unreachable;
        }

        return (int) ($rows[0]['cnt'] ?? 0) === 1 ? ResultStoreAvailability::Ready : ResultStoreAvailability::Empty;
    }

    public function totalsByStatus(OrderSource $source): array
    {
        $query = $this->results(source: $source)
            ->columns('status')
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->aggregate(Aggregate::sum(column: 'amount_cents', alias: 'amount_cents'))
            ->groupBy('status')
            ->orderBy(Sort::only(['status'])->withOrder(['status' => 'asc']))
            ->build();
        $rows = $this->client()->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        return array_map(
            callback: static fn (array $row): array => ['status' => (string) $row['status'], 'orders' => (int) $row['orders'], 'amountCents' => (int) $row['amount_cents']],
            array: $rows,
        );
    }

    public function latest(OrderSource $source, int $limit): array
    {
        $query = $this->results(source: $source)
            ->columns('order_id', 'status', 'amount_cents')
            ->scalar(Scalar::toString(column: 'occurred_at', alias: 'occurred_at'))
            ->orderBy(Sort::only(['occurred_at'])->withOrder(['occurred_at' => 'desc']))
            ->limit(limit: $limit)
            ->build();
        $rows = $this->client()->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        return array_map(
            callback: static fn (array $row): array => ['orderId' => (string) $row['order_id'], 'status' => (string) $row['status'], 'amountCents' => (int) $row['amount_cents'], 'occurredAt' => (string) $row['occurred_at']],
            array: $rows,
        );
    }

    public function summary(OrderSource $source): array
    {
        $query = $this->results(source: $source)
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->aggregate(Aggregate::sum(column: 'amount_cents', alias: 'amount_cents'))
            ->build();
        $rows = $this->client()->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        return ['orders' => (int) ($rows[0]['orders'] ?? 0), 'amountCents' => (int) ($rows[0]['amount_cents'] ?? 0)];
    }

    /**
     * One source's rows, deduplicated: FINAL, because delivery is
     * at-least-once and ReplacingMergeTree collapses retries only on merge.
     */
    private function results(OrderSource $source): SelectQuery
    {
        return ClickHouseQueryBuilder::create(allowedFields: [])
            ->withMandatoryFilter(filter: new Equals(field: 'source', value: $source->value))
            ->select(table: self::TABLE)
            ->final();
    }

    private function client(): ClickHouseClient
    {
        return $this->client ??= $this->clientFactory->create();
    }
}
