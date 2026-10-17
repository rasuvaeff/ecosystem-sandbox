<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exception;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Exception\DomainException;

/**
 * A payment found charged arrived before the decline that gave it up had
 * failed the order. Thrown, not returned: the reconciliation's transaction
 * must roll back so the outbox repeats it once the order has failed.
 */
final class PaymentNotFailedYetException extends DomainException
{
    public function __construct(public readonly OrderId $orderId)
    {
        parent::__construct(message: 'The order has not failed yet: the recovery is repeated later');
    }
}
