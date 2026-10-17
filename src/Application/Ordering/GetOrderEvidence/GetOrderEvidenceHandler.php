<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderEvidence;

use App\Application\Ordering\GetOrder\OrderView;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderRepository;
use App\Domain\Ordering\TransitionHistory;
use App\Domain\Ordering\TransitionRecord;

/**
 * Everything the database holds about one order — the web UI's source: the
 * page shows only what this returns, never a step moved on a timer.
 */
final readonly class GetOrderEvidenceHandler
{
    public function __construct(
        private OrderRepository $orders,
        private TransitionHistory $history,
        private OrderLifecycle $lifecycle,
        private OrderActivityReader $activity,
    ) {}

    /**
     * @throws OrderNotFoundException
     */
    public function handle(GetOrderEvidenceQuery $query): OrderEvidence
    {
        $order = $this->orders->find(id: $query->orderId) ?? throw new OrderNotFoundException(orderId: $query->orderId);
        $transitions = $this->history->forOrder(orderId: $query->orderId);
        $audit = $this->activity->audit(orderId: $query->orderId);
        // Two audit rows written within one second have no order of their
        // own; the status they led to places them along the transitions taken.
        $rank = array_flip(array: array_map(callback: static fn (TransitionRecord $record): string => $record->to, array: $transitions));
        usort($audit, static function (AuditEntry $a, AuditEntry $b) use ($rank): int {
            $byTime = strcmp($a->at, $b->at);

            return $byTime !== 0
                ? $byTime
                : ($rank[$a->statusSetTo()] ?? PHP_INT_MAX) <=> ($rank[$b->statusSetTo()] ?? PHP_INT_MAX);
        });

        return new OrderEvidence(
            order: OrderView::of(order: $order),
            transitions: $transitions,
            audit: $audit,
            outbox: $this->activity->outbox(orderId: $query->orderId),
            maxAttempts: $this->activity->maxAttempts(),
            nextTransitions: $this->lifecycle->possibleTransitions(order: $order),
        );
    }
}
