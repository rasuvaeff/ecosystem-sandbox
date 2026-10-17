<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\ClickHouse;

use App\Application\Analytics\GetAnalyticsReport\ExperimentResults;
use App\Domain\Marketing\CheckoutConversion;
use App\Domain\Shared\TableNames;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxEventType;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * The pay-button A/B test as yii3-ab-testing-clickhouse's tables hold it.
 * Read with FINAL (at-least-once delivery); only `decision_reason = assigned`
 * counts. The join is beyond clickhouse-toolkit's builder, so the skeleton
 * is a constant and every value is bound as {name:Type}.
 */
final readonly class ClickHouseExperimentResults implements ExperimentResults
{
    private const string VARIANTS = 'SELECT e.variant AS variant, uniqExact(e.subject_id) AS visitors, uniqExactIf(e.subject_id, c.subject_id != \'\') AS converted '
        . 'FROM (SELECT variant, subject_id FROM ' . AnalyticsSchemaV2::EXPOSURES_TABLE . ' FINAL '
        . 'WHERE experiment = {experiment:String} AND decision_reason = {assigned:String}) AS e '
        . 'LEFT JOIN (SELECT DISTINCT variant, subject_id FROM ' . AnalyticsSchemaV2::CONVERSIONS_TABLE . ' FINAL '
        . 'WHERE experiment = {experiment:String} AND decision_reason = {assigned:String} AND goal = {goal:String}) AS c '
        . 'ON c.variant = e.variant AND c.subject_id = e.subject_id '
        . 'GROUP BY e.variant ORDER BY e.variant';

    public function __construct(
        private ClickHouseClientFactory $clientFactory,
        private AbTestingSchema $schema,
        private ConnectionInterface $db,
    ) {}

    public function variants(string $experiment): array
    {
        $client = $this->clientFactory->create();
        if (!$this->schema->isInstalled()) {
            return [];
        }

        $rows = $client->selectWithParams(
            self::VARIANTS,
            ['experiment' => $experiment, 'assigned' => 'assigned', 'goal' => CheckoutConversion::GOAL],
            new JsonEachRow(),
        )->data;

        return array_map(
            callback: static fn (array $row): array => ['variant' => (string) $row['variant'], 'visitors' => (int) $row['visitors'], 'converted' => (int) $row['converted']],
            array: $rows,
        );
    }

    public function pendingEvents(): int
    {
        return (int) new Query(db: $this->db)
            ->from(tables: TableNames::OUTBOX)
            ->where(condition: ['type' => [AbTestingOutboxEventType::Exposure->value, AbTestingOutboxEventType::Conversion->value], 'status' => 'pending'])
            ->count();
    }
}
