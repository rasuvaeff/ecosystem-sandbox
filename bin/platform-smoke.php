<?php

declare(strict_types=1);

use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3Recaptcha\VerificationResult;
use Rasuvaeff\Yii3Tenancy\ConfigTenantProvider;
use Rasuvaeff\Yii3Tenancy\HeaderTenantResolver;
use Rasuvaeff\Yii3Tenancy\TenantStatus;
use function Rasuvaeff\Understudy\when;

require dirname(__DIR__) . '/vendor/autoload.php';

$request = new ServerRequest(headers: ['X-Tenant-Id' => 'acme'], method: 'GET', uri: '/checkout');
$tenantId = new HeaderTenantResolver()->resolve($request);
$tenant = new ConfigTenantProvider([
    'acme' => ['name' => 'Acme', 'status' => TenantStatus::Active->value],
])->find($tenantId ?? '');
if ($tenant?->id !== 'acme') {
    throw new RuntimeException('Tenancy header/provider resolution failed');
}

$ab = new AbTesting(
    provider: new ConfigExperimentProvider([
        'checkout-button' => ['salt' => 'sandbox-v1', 'fallbackVariant' => 'control', 'variants' => ['control' => 50, 'green' => 50]],
    ]),
    strategy: new WeightedHashAssignmentStrategy(),
);
$first = $ab->assign(experiment: 'checkout-button', subjectId: 'customer-42');
$second = $ab->assign(experiment: 'checkout-button', subjectId: 'customer-42');
if ($first->variant !== $second->variant) {
    throw new RuntimeException('A/B assignment is not deterministic');
}
$exposure = $ab->trackExposure($first);
$conversion = $ab->trackConversion($first, goal: 'purchase', exposure: $exposure);
if ($conversion->experiment !== 'checkout-button' || $conversion->goal !== 'purchase') {
    throw new RuntimeException('A/B exposure/conversion event contract failed');
}

$message = OutboxMessage::create(
    type: 'order.paid',
    payload: json_encode(['orderId' => 'order-42', 'amount' => 1999], JSON_THROW_ON_ERROR),
    id: 'event-42',
);
$route = new MapClickHouseMessageRouter([
    'order.paid' => ['table' => 'order_events', 'columns' => ['event_id', 'orderId', 'amount']],
])->route($message);
if ($route->row !== ['event_id' => 'event-42', 'orderId' => 'order-42', 'amount' => 1999]) {
    throw new RuntimeException('ClickHouse outbox route projection failed');
}

$streamFactory = new StreamFactory();
$store = new InMemoryStore('memory', $streamFactory);
$upload = Upload::fromStream(
    stream: $streamFactory->createStream('receipt'),
    originalName: 'receipt.txt',
    streamFactory: $streamFactory,
    clientMediaTypeHint: 'text/plain',
);
$stored = $store->write($upload, 'receipts', new RandomPathGenerator(), 'text/plain');
if ($store->bytesAt($stored->relativePath) !== 'receipt') {
    throw new RuntimeException('File storage upload/read failed');
}

$captcha = new VerificationResult(success: true, score: 0.97, action: 'checkout');
if (!$captcha->success || $captcha->isTransportError() || $captcha->score < 0.9) {
    throw new RuntimeException('reCAPTCHA verification result contract failed');
}

$double = Understudy::for(PlatformGateway::class);
when(static fn () => $double->status())->returns('ok');
if ($double->status() !== 'ok') {
    throw new RuntimeException('Understudy typed stub failed');
}

echo "PLATFORM PASS: tenancy=ok ab-testing=ok clickhouse-route=ok filestorage=ok recaptcha=ok understudy=ok\n";

interface PlatformGateway
{
    public function status(): string;
}
