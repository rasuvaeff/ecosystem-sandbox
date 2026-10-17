<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Realtime;

use App\Application\Ordering\Integration\OrderChannel;
use App\Application\Ordering\Integration\OrderResultEvent;
use App\Infrastructure\Shared\Outbox\IntegrationMessages;
use Rasuvaeff\Yii3Centrifugo\CentrifugoClientInterface;
use Rasuvaeff\Yii3Centrifugo\PublishOptions;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;

/**
 * The live order page's projection: a payment result is pushed to the
 * order's own Centrifugo channel, deduped by the outbox message id.
 */
final readonly class PushOrderResultToBrowser implements PublisherInterface
{
    public function __construct(
        private IntegrationMessages $messages,
        private CentrifugoClientInterface $centrifugo,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $result = $this->messages->parse(message: $message, expected: OrderResultEvent::class);
        $this->centrifugo->publish(
            channel: OrderChannel::of(orderId: $result->orderId()),
            data: ['orderId' => $result->orderId(), 'status' => $result->status()],
            options: new PublishOptions(idempotencyKey: $message->getId()),
        );
    }
}
