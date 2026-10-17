<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static fn (string $name, string $default): string => (($value = getenv($name)) === false || $value === '') ? $default : $value;
$table = 'sandbox_order_paid_events';
$clientFactory = new ClickHouseClientFactory(new ClickHouseConfig(
    host: $env('CLICKHOUSE_HOST', 'clickhouse'),
    port: (int) $env('CLICKHOUSE_PORT', '8123'),
    database: $env('CLICKHOUSE_DB', 'sandbox'),
    username: $env('CLICKHOUSE_USER', 'sandbox'),
    password: $env('CLICKHOUSE_PASSWORD', 'sandbox'),
));
$client = $clientFactory->create();
$client->executeQuery('DROP TABLE IF EXISTS ' . $table);
$client->executeQuery(
    'CREATE TABLE ' . $table . ' (event_id String, order_id String, status String, amount_cents UInt64) '
    . 'ENGINE = ReplacingMergeTree ORDER BY event_id',
);

$storage = new InMemoryStorage();
$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    }
};
$message = new Outbox(storage: $storage, clock: $clock)->record(
    type: 'order.paid',
    payload: json_encode(['order_id' => 'analytics-demo', 'status' => 'paid', 'amount_cents' => 1999], JSON_THROW_ON_ERROR),
    id: 'analytics-event-1',
);
$exporter = new ClickHouseOutboxExporter(
    storage: $storage,
    router: new MapClickHouseMessageRouter([
        'order.paid' => ['table' => $table, 'columns' => ['event_id', 'order_id', 'status', 'amount_cents']],
    ]),
    retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
    clock: $clock,
    writerFactory: new DefaultClickHouseWriterFactory(clientFactory: $clientFactory),
);
$result = $exporter->exportOrFail();
if ($result->published !== 1 || $storage->getById($message->getId())?->getStatus() !== OutboxStatus::Published) {
    throw new RuntimeException('ClickHouse exporter did not publish the outbox event');
}
$reader = new ClickHouseDataReader(
    client: $client,
    table: $table,
    queryBuilder: ClickHouseQueryBuilder::create(allowedFields: ['event_id', 'order_id', 'status', 'amount_cents']),
    mapper: static fn (array $row): array => $row,
    columns: ['event_id', 'order_id', 'status', 'amount_cents'],
);
$row = $reader->readOne();
if (!is_array($row) || ($row['event_id'] ?? null) !== 'analytics-event-1' || ($row['status'] ?? null) !== 'paid') {
    throw new RuntimeException('ClickHouse round-trip row did not match the outbox event');
}
echo "ANALYTICS ROUNDTRIP PASS: outbox=published clickhouse=read-back table={$table}\n";
