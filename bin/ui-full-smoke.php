<?php

declare(strict_types=1);

/**
 * `make ui-full`: payment results reach ClickHouse through the same fan-out
 * as the webhooks. Every event there is delivered at least twice (the webhook
 * receiver refuses the first attempt), and the analytics must still count each
 * order once. An order that did not come from the shop page stays out of the
 * report, and Grafana reads the same table through its ClickHouse datasource.
 */

use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new Client(['base_uri' => getenv('BASE_URL') ?: 'http://127.0.0.1:8080', 'http_errors' => false]);
$run = 'ui-full-' . time();
$orders = ["{$run}-paid" => [2500, 'paid'], "{$run}-declined" => [4002, 'payment_failed']];
$scripted = "{$run}-scripted";

$before = json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR);
foreach ($orders as $id => [$amount]) {
    $client->post('/api/v1/checkout', ['json' => ['orderId' => $id, 'customerId' => App\Domain\Analytics\OrderSource::SHOP_CUSTOMER_ID, 'amountCents' => $amount]]);
}
$client->post('/api/v1/checkout', ['json' => ['orderId' => $scripted, 'customerId' => 'ui-full', 'amountCents' => 2600]]);

$seen = [];
$excludedGrew = false;
for ($attempt = 0; $attempt < 30 && (count($seen) < count($orders) || !$excludedGrew); ++$attempt) {
    sleep(1);
    $analytics = json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $seen = array_values(array_filter($analytics['recent'], static fn (array $row): bool => isset($orders[$row['orderId']])));
    $excludedGrew = $analytics['excluded']['orders'] === $before['excluded']['orders'] + 1
        && $analytics['excluded']['amountCents'] === $before['excluded']['amountCents'] + 2600;
}

if (in_array($scripted, array_column($analytics['recent'], 'orderId'), true) || !$excludedGrew) {
    fwrite(STDERR, 'UI FULL SMOKE FAIL: a scripted order must be counted apart, not in the report: ' . json_encode($analytics) . "\n");
    exit(1);
}
if (($analytics['available'] ?? false) !== true || count($seen) !== count($orders)) {
    fwrite(STDERR, 'UI FULL SMOKE FAIL: analytics did not receive both orders exactly once: ' . json_encode($analytics ?? null) . "\n");
    exit(1);
}
foreach ($seen as $row) {
    [$amount, $status] = $orders[$row['orderId']];
    if ($row['amountCents'] !== $amount || $row['status'] !== $status) {
        fwrite(STDERR, 'UI FULL SMOKE FAIL: wrong analytics row ' . json_encode($row) . "\n");
        exit(1);
    }
}

// The inbox: every result message was handled exactly once by each sink, even
// though the webhook receiver refused its first attempt and the fan-out
// retried the message for all of them.
/** @var Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/support/container.php';
$db = $container->get(Yiisoft\Db\Connection\ConnectionInterface::class);
$sinks = array_values(array_filter(array_map('trim', explode(',', (string) getenv('RESULT_SINKS')))));
sort($sinks);
$messageIds = new Yiisoft\Db\Query\Query($db)
    ->select('id')
    ->from(App\Domain\Shared\TableNames::OUTBOX)
    ->where(['aggregate_id' => array_keys($orders), 'type' => [App\Application\Ordering\Integration\OrderPaid::type(), App\Application\Ordering\Integration\OrderPaymentFailed::type()]])
    ->column();
if (count($messageIds) !== count($orders)) {
    fwrite(STDERR, 'UI FULL SMOKE FAIL: one result message per order expected, got ' . count($messageIds) . "\n");
    exit(1);
}
$handled = static fn (string $messageId): array => new Yiisoft\Db\Query\Query($db)
    ->select('consumer')
    ->from(App\Domain\Shared\TableNames::INBOX)
    ->where(['message_id' => $messageId])
    ->orderBy(['consumer' => SORT_ASC])
    ->column();
// The refused webhook is retried after the outbox delay; the sinks that
// delivered the first time must not be run again meanwhile.
for ($attempt = 0; $attempt < 40 && array_filter($messageIds, static fn (string $id): bool => $handled($id) !== $sinks) !== []; ++$attempt) {
    sleep(1);
}
foreach ($messageIds as $messageId) {
    if ($handled($messageId) !== $sinks) {
        fwrite(STDERR, "UI FULL SMOKE FAIL: result message {$messageId} must be handled once by each sink, got " . json_encode($handled($messageId)) . "\n");
        exit(1);
    }
}

// The pay-button A/B test: a visitor sees a variant (exposure), checks out with
// the signed receipt (conversion), and the worker exports both to ClickHouse.
// A forged receipt converts nothing.
$converted = static fn (array $report): int => array_sum(array_column($report['experiment']['variants'], 'converted'));
$abBefore = $converted(json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR));
$visitor = new Client(['base_uri' => getenv('BASE_URL') ?: 'http://127.0.0.1:8080', 'http_errors' => false, 'cookies' => true]);
$shown = json_decode((string) $visitor->get('/api/v1/experiments/checkout-button')->getBody(), true, 512, JSON_THROW_ON_ERROR);
$again = json_decode((string) $visitor->get('/api/v1/experiments/checkout-button')->getBody(), true, 512, JSON_THROW_ON_ERROR);
if ($again['variant'] !== $shown['variant']) {
    fwrite(STDERR, "UI FULL SMOKE FAIL: the same visitor got another variant on the second view\n");
    exit(1);
}
$shop = App\Domain\Analytics\OrderSource::SHOP_CUSTOMER_ID;
$visitor->post('/api/v1/checkout', ['headers' => ['X-Ab-Receipt' => $shown['receipt']], 'json' => ['orderId' => "{$run}-ab", 'customerId' => $shop, 'amountCents' => 2700]]);
$visitor->post('/api/v1/checkout', ['headers' => ['X-Ab-Receipt' => 'forged.' . str_repeat('0', 64)], 'json' => ['orderId' => "{$run}-ab-forged", 'customerId' => $shop, 'amountCents' => 2800]]);
$abAfter = $abBefore;
for ($attempt = 0; $attempt < 30 && $abAfter === $abBefore; ++$attempt) {
    sleep(1);
    $abAfter = $converted(json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR));
}
// A second conversion (the forged receipt) would land in the same export batch
// or the next one; give it that next one before counting.
sleep(2);
$report = json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR);
if ($converted($report) !== $abBefore + 1 || !in_array($shown['variant'], array_column($report['experiment']['variants'], 'variant'), true)) {
    fwrite(STDERR, 'UI FULL SMOKE FAIL: one signed receipt must convert exactly once, the forged one never: ' . json_encode($report['experiment']) . "\n");
    exit(1);
}

// Grafana's ClickHouse panel: the datasource answers the dashboard's own query.
$grafana = new Client(['base_uri' => getenv('GRAFANA_URL') ?: 'http://grafana:3000', 'http_errors' => false]);
$dashboard = json_decode((string) $grafana->get('/api/dashboards/uid/rasuvaeff-sandbox')->getBody(), true, 512, JSON_THROW_ON_ERROR);
$table = array_values(array_filter($dashboard['dashboard']['panels'] ?? [], static fn (array $panel): bool => ($panel['title'] ?? '') === 'Latest results (shop page)'));
if ($table === []) {
    fwrite(STDERR, "UI FULL SMOKE FAIL: the Grafana dashboard has no ClickHouse table panel\n");
    exit(1);
}
$answer = $grafana->post('/api/ds/query', ['json' => ['queries' => $table[0]['targets'], 'from' => 'now-1h', 'to' => 'now']]);
$frames = json_decode((string) $answer->getBody(), true, 512, JSON_THROW_ON_ERROR)['results']['A']['frames'] ?? [];
$grafanaOrders = $frames[0]['data']['values'][0] ?? [];
if ($answer->getStatusCode() !== 200 || !in_array("{$run}-paid", $grafanaOrders, true) || in_array($scripted, $grafanaOrders, true)) {
    fwrite(STDERR, 'UI FULL SMOKE FAIL: Grafana did not read the shop orders from ClickHouse: ' . $answer->getStatusCode() . ' ' . substr((string) $answer->getBody(), 0, 500) . "\n");
    exit(1);
}

$abPanel = array_values(array_filter($dashboard['dashboard']['panels'] ?? [], static fn (array $panel): bool => str_starts_with((string) ($panel['title'] ?? ''), 'A/B test')));
$abAnswer = $abPanel === [] ? null : $grafana->post('/api/ds/query', ['json' => ['queries' => $abPanel[0]['targets'], 'from' => 'now-1h', 'to' => 'now']]);
$abFrames = $abAnswer === null ? [] : (json_decode((string) $abAnswer->getBody(), true, 512, JSON_THROW_ON_ERROR)['results']['A']['frames'] ?? []);
if (!in_array($shown['variant'], $abFrames[0]['data']['values'][0] ?? [], true)) {
    fwrite(STDERR, "UI FULL SMOKE FAIL: Grafana's A/B panel does not show the variant from ClickHouse\n");
    exit(1);
}

echo "UI FULL SMOKE PASS: paid and payment_failed exported to ClickHouse once each despite the webhook retry, inbox: each result handled once by " . implode('+', $sinks) . ", a scripted order counted apart, Grafana reads the shop orders from ClickHouse, A/B {$shown['variant']} converted once (forged receipt ignored)\n";
