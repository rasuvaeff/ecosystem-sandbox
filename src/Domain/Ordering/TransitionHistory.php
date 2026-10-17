<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * The transitions an order went through, oldest first, as the lifecycle
 * recorded them.
 */
interface TransitionHistory
{
    /**
     * @return list<TransitionRecord>
     */
    public function forOrder(OrderId $orderId): array;
}
