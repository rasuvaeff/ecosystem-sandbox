<?php

declare(strict_types=1);

namespace App\Application\Operations\GetCheckoutTrace;

use App\Domain\Ordering\OrderId;

interface CheckoutTraceLocator
{
    /**
     * @return string|null|false the checkout's trace id; null when it carried none; false when no checkout was recorded for the order
     */
    public function traceIdOf(OrderId $orderId): null|string|false;
}
