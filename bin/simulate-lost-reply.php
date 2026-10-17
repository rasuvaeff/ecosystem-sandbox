<?php

declare(strict_types=1);

/**
 * Run by bin/simulate-lost-reply.sh inside the app container, with
 * RESULT_SINKS=webhooks so the result Processor delivers to the partner only.
 */

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use App\Application\Payments\Integration\PaymentReconciliationRequested;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\PaymentIdempotencyKey;
use App\Domain\Shared\TableNames;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

require dirname(__DIR__) . '/vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, "LOST REPLY FAIL: {$message}\n");
    exit(1);
}

function yii(string $command): void
{
    passthru(dirname(__DIR__) . '/yii ' . $command . ' >/dev/null', $status);
    if ($status !== 0) {
        fail("./yii {$command} exited with {$status}");
    }
}

/** @var ContainerInterface $container */
$container = require __DIR__ . '/support/container.php';
$db = $container->get(ConnectionInterface::class);
$app = new Client(['base_uri' => 'http://127.0.0.1:8080', 'http_errors' => false]);
$bank = new Client(['base_uri' => 'http://payment-mock:8081', 'http_errors' => false]);
$partner = new Client(['base_uri' => 'http://webhook-mock:8082', 'http_errors' => false]);
$json = static fn (Psr\Http\Message\ResponseInterface $response): array => json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

$orderId = 'lost-reply-' . time();
$key = PaymentIdempotencyKey::forOrder(orderId: new OrderId(value: $orderId));
$accepted = $app->post('/api/v1/checkout', ['json' => ['orderId' => $orderId, 'customerId' => 'lost-reply', 'amountCents' => 5040]]);
$accepted->getStatusCode() === 202 || fail('checkout must be accepted, got ' . $accepted->getStatusCode());

// Every attempt the outbox makes: the bank answers each with a 503 (the first
// one after taking the money), the last one is given up.
for ($attempt = 0; $attempt < 3; ++$attempt) {
    yii('sandbox:outbox:run');
}
$evidence = $json($app->get('/api/v1/orders/' . rawurlencode($orderId) . '/evidence'));
$evidence['order']['status'] === 'payment_failed' || fail('the order must fail once the payment is given up, got ' . $evidence['order']['status']);
($evidence['payment']['status'] ?? null) === 'abandoned' || fail('the saga must be abandoned before reconciling, got ' . json_encode($evidence['payment']));
$requested = new Query($db)->from(TableNames::OUTBOX)->where(['aggregate_id' => $orderId, 'type' => PaymentReconciliationRequested::type()])->count();
$requested === 1 || fail("giving up must ask for one reconciliation, got {$requested}");

// The reconciliation: the bank holds the charge, so the failed order is paid.
yii('sandbox:outbox:run');
$evidence = $json($app->get('/api/v1/orders/' . rawurlencode($orderId) . '/evidence'));
($evidence['payment']['status'] ?? null) === 'recovered' || fail('the reconciliation must recover the found charge, got ' . json_encode($evidence['payment']));
$evidence['order']['status'] === 'paid' || fail('a found charge must pay the order, got ' . $evidence['order']['status']);
$transitions = array_column($evidence['transitions'], 'transition');
$transitions === ['start_payment', 'mark_failed', 'recover_payment'] || fail('the order must fail and then recover, got ' . json_encode($transitions));

// Delivered again: nothing more happens at the bank or to the order.
yii('sandbox:outbox:run');
$ledger = $json($bank->get('/ledger/' . rawurlencode($key)));
$ledger['charges'] === 1 || fail('the bank must hold one charge, got ' . json_encode($ledger));
$recoveries = count(array_filter($json($app->get('/api/v1/orders/' . rawurlencode($orderId) . '/evidence'))['transitions'], static fn (array $row): bool => $row['transition'] === 'recover_payment'));
$recoveries === 1 || fail("a redelivered reconciliation must not move the order again, got {$recoveries} recoveries");

// The partner hears the failure, then the payment — each exactly once.
yii('sandbox:projections:run');
yii('sandbox:projections:run');
$deliveries = $json($partner->get('/deliveries'))['received'] ?? [];
foreach ([OrderPaymentFailed::type(), OrderPaid::type()] as $type) {
    $eventId = new Query($db)->select('id')->from(TableNames::OUTBOX)->where(['aggregate_id' => $orderId, 'type' => $type])->scalar();
    is_string($eventId) || fail("the order must have its {$type} event");
    $received = array_filter($deliveries, static fn (array $entry): bool => $entry['eventId'] === $eventId && (int) $entry['status'] === 204);
    count($received) === 1 || fail("the partner must receive {$type} exactly once, got " . count($received));
}

echo "LOST REPLY PASS: bank charged once, order payment_failed then paid (recover_payment once), saga recovered, partner told failed then paid, once each\n";
