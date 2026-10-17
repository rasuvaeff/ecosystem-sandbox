<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * Applies a lifecycle transition exactly once per idempotency key: a key seen
 * before is a no-op. `$then` runs inside the same transaction, after the
 * transition is recorded, so whatever the transition causes commits with it.
 */
interface OrderLifecycle
{
    /**
     * @param non-empty-string $idempotencyKey
     * @param callable(): void $then
     *
     * @return bool whether the transition was applied now
     */
    public function apply(Order $order, OrderTransition $transition, string $idempotencyKey, callable $then): bool;

    /**
     * What the lifecycle allows from the order's current status — asked of
     * the lifecycle itself, so a guard or a new transition shows up unchanged.
     *
     * @return list<OrderTransition>
     */
    public function possibleTransitions(Order $order): array;
}
