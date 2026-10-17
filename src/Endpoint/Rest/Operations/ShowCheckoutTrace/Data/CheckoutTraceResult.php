<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowCheckoutTrace\Data;

/**
 * The checkout's trace as Jaeger holds it, and the request budget the read
 * ran under (rasuvaeff/context-http).
 */
final readonly class CheckoutTraceResult implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $spans
     */
    public function __construct(
        public string $orderId,
        public ?string $traceId,
        public array $spans,
        public TraceBudgetResult $budget,
    ) {}

    /**
     * @return array{orderId: string, traceId: ?string, spans: list<array<string, mixed>>, budget: TraceBudgetResult}
     */
    public function jsonSerialize(): array
    {
        return ['orderId' => $this->orderId, 'traceId' => $this->traceId, 'spans' => $this->spans, 'budget' => $this->budget];
    }
}
