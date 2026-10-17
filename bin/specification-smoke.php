<?php

declare(strict_types=1);

use Rasuvaeff\Specification\QueryApplier;
use Rasuvaeff\Specification\SpecificationBuilder;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Query\Query;

require dirname(__DIR__) . '/vendor/autoload.php';

$driver = new Driver(
    dsn: sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('DB_HOST') ?: 'postgres',
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME') ?: 'sandbox',
    ),
    username: (string) (getenv('DB_USER') ?: 'sandbox'),
    password: (string) (getenv('DB_PASSWORD') ?: 'sandbox'),
);
$db = new Connection(
    driver: $driver,
    schemaCache: new SchemaCache(new FileCache(dirname(__DIR__) . '/runtime/cache')),
);

$query = new Query($db)
    ->select(['id', 'status', 'amount_cents'])
    ->from('orders');
$specification = SpecificationBuilder::create()
    ->whereEqual('status', 'paid')
    ->whereGreaterThanOrEqual('amount_cents', 1000)
    ->orderBy(['amount_cents' => SORT_DESC])
    ->limit(10)
    ->build();
QueryApplier::apply(specification: $specification, query: $query);
$rows = $query->all();

foreach ($rows as $row) {
    if (($row['status'] ?? null) !== 'paid' || (int) ($row['amount_cents'] ?? 0) < 1000) {
        throw new RuntimeException('Specification returned a row outside its whitelist');
    }
}

$where = $query->getWhere();
$whereJson = json_encode($where, JSON_THROW_ON_ERROR);
if (!str_contains($whereJson, 'status') || !str_contains($whereJson, 'amount_cents')) {
    throw new RuntimeException('Specification did not build a parameterized condition');
}

echo 'SPECIFICATION PASS: rows=' . count($rows) . " whitelist=status,amount_cents order=amount_cents.desc\n";
