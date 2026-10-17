<?php

declare(strict_types=1);

namespace App\Application\Operations\GetCheckoutTrace;

use App\Domain\Ordering\Exception\OrderNotFoundException;

/**
 * The trace of one order's checkout: the trace id the checkout carried into
 * the outbox, its spans from the trace store — read under the request's
 * budget (rasuvaeff/context), so a slow store costs a 504, not a hung page.
 */
final readonly class GetCheckoutTraceHandler
{
    public function __construct(
        private CheckoutTraceLocator $locator,
        private TraceStore $traces,
    ) {}

    /**
     * @throws OrderNotFoundException no checkout was recorded for the order
     *
     * @return array{traceId: ?string, spans: list<array<string, mixed>>}
     */
    public function handle(GetCheckoutTraceQuery $query): array
    {
        $traceId = $this->locator->traceIdOf(orderId: $query->orderId);
        if ($traceId === false) {
            throw new OrderNotFoundException(orderId: $query->orderId);
        }

        return ['traceId' => $traceId, 'spans' => $traceId === null ? [] : $this->traces->spans(traceId: $traceId, budget: $query->budget)];
    }
}
