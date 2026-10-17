<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\DbUtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;

require dirname(__DIR__) . '/vendor/autoload.php';

$driver = new Driver(
    dsn: sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: 'postgres', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'sandbox'),
    username: (string) (getenv('DB_USER') ?: 'sandbox'),
    password: (string) (getenv('DB_PASSWORD') ?: 'sandbox'),
);
$db = new Connection(driver: $driver, schemaCache: new SchemaCache(new FileCache(dirname(__DIR__) . '/runtime/cache')));
$clock = new class implements ClockInterface {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@1750000000');
    }
};

$runId = (string) time();
$event = WebhookEvent::create(type: 'order.paid', payload: '{"orderId":"db-adapter"}', occurredAt: $clock->now(), id: 'db-adapter-event-' . $runId);
$endpoint = new WebhookEndpoint(url: 'https://partner.example.com/orders', secret: 'sandbox-secret');
$delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint, createdAt: $clock->now(), id: 'db-adapter-delivery-' . $runId);
$webhooks = new DbWebhookDeliveryStorage(db: $db, table: new WebhookDeliveryTableName()->value);
$webhooks->save($delivery);
$webhooks->save($delivery->withAttempt(at: $clock->now()));
$storedDelivery = $webhooks->getById($delivery->getId());
if ($storedDelivery === null || $storedDelivery->getAttempts() !== 1) {
    throw new RuntimeException('Webhook DB storage did not preserve protected status/attempt state');
}

$touchpoint = new UtmTouchpoint(
    utm: new UtmParameters(source: 'newsletter', medium: 'email', campaign: 'db-demo'),
    clickIds: \Rasuvaeff\Yii3Utm\ClickIds::empty(),
    referrer: null,
    landingPage: null,
    occurredAt: $clock->now(),
);
$entityId = 'db-order-' . $runId;
$attribution = new UtmAttribution(entityId: $entityId, eventId: 'db-purchase-' . $runId, interactionType: InteractionType::purchase(), touchpoint: $touchpoint);
$utm = new DbUtmAttributionRepository(db: $db, clock: $clock, table: new UtmAttributionTableName(App\Domain\Shared\TableNames::UTM_ATTRIBUTION));
if (!$utm->append($attribution) || $utm->append($attribution) || $utm->countByEntity($entityId) !== 1) {
    throw new RuntimeException('UTM DB journal did not deduplicate by unique key');
}

echo "DB ADAPTERS PASS: webhook_pending=1 webhook_attempts=1 utm_rows=1 deduplicated=yes\n";
