<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Event;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * A placed order was credited to the campaign that brought it. The channel
 * is resolved once, here, from the real touchpoint (click ids and referrer
 * included), so no reader has to rebuild it.
 */
final readonly class CheckoutAttributed implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public string $channel,
        public ?string $source,
        public ?string $medium,
        public ?string $campaign,
    ) {}
}
