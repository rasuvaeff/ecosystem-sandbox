<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Shared\Data;

use App\Domain\Ordering\TransitionRecord;

/**
 * One workflow transition of an order, in the timeline and the evidence.
 */
final readonly class TransitionResult implements \JsonSerializable
{
    public function __construct(
        public string $transition,
        public string $from,
        public string $to,
        public string $at,
    ) {}

    public static function fromRecord(TransitionRecord $record): self
    {
        return new self(transition: $record->transition, from: $record->from, to: $record->to, at: $record->at->format(format: DATE_RFC3339));
    }

    /**
     * @param list<TransitionRecord> $records
     *
     * @return list<self>
     */
    public static function list(array $records): array
    {
        $results = [];
        foreach ($records as $record) {
            $results[] = self::fromRecord(record: $record);
        }

        return $results;
    }

    /**
     * @return array{transition: string, from: string, to: string, at: string}
     */
    public function jsonSerialize(): array
    {
        return ['transition' => $this->transition, 'from' => $this->from, 'to' => $this->to, 'at' => $this->at];
    }
}
