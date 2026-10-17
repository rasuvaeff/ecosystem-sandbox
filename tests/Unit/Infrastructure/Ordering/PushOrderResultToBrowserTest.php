<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Ordering;

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Infrastructure\Ordering\Realtime\PushOrderResultToBrowser;
use App\Tests\Support\IntegrationEvents;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Centrifugo\CentrifugoClientInterface;
use Rasuvaeff\Yii3Centrifugo\PublishOptions;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;

/**
 * Both payment results are recorded in the outbox, so both need a consumer:
 * an event type no worker claims stays pending forever.
 */
#[Test]
#[Covers(PushOrderResultToBrowser::class)]
final class PushOrderResultToBrowserTest
{
    #[DataProvider('paymentResultProvider')]
    public function aPaymentResultIsPublishedWithItsStatus(string $type, string $status): void
    {
        $centrifugo = Understudy::for(CentrifugoClientInterface::class);

        new PushOrderResultToBrowser(messages: IntegrationEvents::messages(), centrifugo: $centrifugo)->publish(
            OutboxMessage::create(type: $type, payload: '{"orderId":"order-1"}', id: 'event-1'),
        );

        verify(static fn () => $centrifugo->publish(
            'orders:order-1',
            ['orderId' => 'order-1', 'status' => $status],
            Arg::satisfies(static fn (mixed $options): bool => $options instanceof PublishOptions && $options->idempotencyKey === 'event-1'),
        ), times: 1);
    }

    public static function paymentResultProvider(): iterable
    {
        yield 'paid' => [OrderPaid::type(), 'paid'];
        yield 'payment failed' => [OrderPaymentFailed::type(), 'payment_failed'];
    }

    #[DataProvider('unusableMessageProvider')]
    public function anUnusableMessageIsTerminal(string $type, string $payload): void
    {
        $centrifugo = Understudy::for(CentrifugoClientInterface::class);

        try {
            new PushOrderResultToBrowser(messages: IntegrationEvents::messages(), centrifugo: $centrifugo)->publish(OutboxMessage::create(type: $type, payload: $payload, id: 'event-2'));
            Assert::fail(message: 'An unusable message must not be published');
        } catch (PublishException $exception) {
            Assert::true($exception->isTerminal());
        }

        verify(static fn () => $centrifugo->publish(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public static function unusableMessageProvider(): iterable
    {
        yield 'a type without a projection' => [PaymentRequested::type(), '{"orderId":"order-1"}'];
        yield 'no order id' => [OrderPaid::type(), '{}'];
        yield 'not json' => [OrderPaid::type(), 'order-1'];
    }
}
