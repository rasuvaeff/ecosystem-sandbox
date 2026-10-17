<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\BodyUtmSource;
use Rasuvaeff\Yii3Utm\Channel;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;

require dirname(__DIR__) . '/vendor/autoload.php';

$clock = new class implements ClockInterface {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@1750000000');
    }
};
$request = new ServerRequest('POST', 'https://sandbox.example.test/api/v1/checkout')
    ->withParsedBody([
        'orderId' => 'utm-order-1',
        'customerId' => 'utm-customer',
        'amountCents' => 2500,
        'utm' => [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'spring-sale',
            'landing_page' => 'https://sandbox.example.test/checkout?utm_campaign=spring-sale&token=drop-me',
        ],
    ]);
$touchpoint = new BodyUtmSource(clock: $clock)->extract($request);
if ($touchpoint === null || $touchpoint->utm->source !== 'newsletter' || $touchpoint->utm->medium !== 'email') {
    throw new RuntimeException('UTM body source did not capture the campaign');
}
if (new DefaultChannelResolver()->resolve($touchpoint) !== Channel::Email || str_contains((string) $touchpoint->landingPage, 'token=')) {
    throw new RuntimeException('UTM channel or landing page sanitization failed');
}

$repository = new InMemoryUtmAttributionRepository(clock: $clock);
$service = new UtmAttributionService(repository: $repository);
$event = new UtmAttributionEvent(
    entityId: 'utm-order-1',
    eventId: 'order.paid-utm-order-1',
    interactionType: InteractionType::purchase(),
    history: UtmHistory::of($touchpoint),
);
$first = $service->record($event);
$replay = $service->record($event);

if ($first !== 1 || $replay !== 0 || $repository->countByEntity('utm-order-1') !== 1) {
    throw new RuntimeException('UTM attribution journal did not deduplicate the purchase event');
}

echo "UTM PASS: source=newsletter channel=email purchase_rows=1 replay_rows=0 payment_payload=clean\n";
