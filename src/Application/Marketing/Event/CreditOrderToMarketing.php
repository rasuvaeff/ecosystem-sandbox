<?php

declare(strict_types=1);

namespace App\Application\Marketing\Event;

use App\Application\Marketing\CheckoutTouchpoints;
use App\Domain\Marketing\CheckoutConversion;
use App\Domain\Marketing\Event\CheckoutAttributed;
use App\Domain\Ordering\Event\OrderPlaced;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\AssignmentReceipt;
use Rasuvaeff\Yii3Utm\ChannelResolver;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;

/**
 * Marketing's reaction to a placed order, in the order's own transaction:
 * the campaign touch becomes an attribution row (yii3-utm-db), the A/B
 * receipt a conversion in the outbox (yii3-ab-testing-outbox). An attributed
 * order never exists without its row; a rolled-back order converts nothing.
 */
final readonly class CreditOrderToMarketing
{
    public function __construct(
        private CheckoutTouchpoints $touchpoints,
        private UtmAttributionService $attribution,
        private AbTesting $abTesting,
        private ChannelResolver $channels,
        private EventDispatcherInterface $events,
    ) {}

    public function __invoke(OrderPlaced $event): void
    {
        $touchpoint = $this->touchpoints->touchpoint();
        if ($touchpoint instanceof UtmTouchpoint) {
            // The journal dedupes a replay itself.
            $this->attribution->record(new UtmAttributionEvent(
                entityId: $event->orderId->value,
                eventId: 'checkout:' . $event->orderId->value,
                interactionType: InteractionType::of(value: 'checkout'),
                history: UtmHistory::of($touchpoint),
            ));
            $utm = $touchpoint->utm;
            $this->events->dispatch(new CheckoutAttributed(
                orderId: $event->orderId,
                channel: $this->channels->resolve($touchpoint)->value,
                source: $utm->source,
                medium: $utm->medium,
                campaign: $utm->campaign,
            ));
        }
        $receipt = $this->touchpoints->receipt();
        if ($receipt instanceof AssignmentReceipt) {
            $this->abTesting->trackConversionForReceipt(receipt: $receipt, goal: CheckoutConversion::GOAL);
        }
    }
}
