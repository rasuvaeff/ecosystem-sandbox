<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowCheckoutTrace\Data;

/**
 * The request budget: the header that shortens it, what it was at the start
 * of the action and what was left at the end (null = no deadline).
 */
final readonly class TraceBudgetResult implements \JsonSerializable
{
    public function __construct(
        public string $header,
        public ?int $limitMs,
        public ?int $remainingMs,
    ) {}

    /**
     * @return array{header: string, limitMs: ?int, remainingMs: ?int}
     */
    public function jsonSerialize(): array
    {
        return ['header' => $this->header, 'limitMs' => $this->limitMs, 'remainingMs' => $this->remainingMs];
    }
}
