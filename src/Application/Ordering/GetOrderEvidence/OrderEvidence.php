<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderEvidence;

use App\Application\Ordering\GetOrder\OrderView;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Ordering\TransitionRecord;

final readonly class OrderEvidence implements \JsonSerializable
{
    /**
     * @param list<TransitionRecord> $transitions
     * @param list<AuditEntry> $audit
     * @param list<OutboxEntry> $outbox
     * @param list<OrderTransition> $nextTransitions
     */
    public function __construct(
        public OrderView $order,
        public array $transitions,
        public array $audit,
        public array $outbox,
        public int $maxAttempts,
        public array $nextTransitions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'order' => [
                'id' => $this->order->orderId->value,
                'customerId' => $this->order->customerId->value,
                'amountCents' => $this->order->amount->cents,
                'status' => $this->order->status->value,
            ],
            'transitions' => array_map(
                callback: static fn (TransitionRecord $record): array => ['transition' => $record->transition, 'from' => $record->from, 'to' => $record->to, 'at' => $record->at->format(format: DATE_RFC3339)],
                array: $this->transitions,
            ),
            'audit' => $this->audit,
            'outbox' => $this->outbox,
            'maxAttempts' => $this->maxAttempts,
            'nextTransitions' => array_map(callback: static fn (OrderTransition $transition): string => $transition->value, array: $this->nextTransitions),
        ];
    }
}
