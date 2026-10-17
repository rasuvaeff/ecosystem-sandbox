<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exception;

use App\Domain\Ordering\OrderId;
use App\Domain\Shared\Exception\DomainException;

final class OrderNotFoundException extends DomainException
{
    public function __construct(public readonly OrderId $orderId)
    {
        parent::__construct(message: 'The order does not exist');
    }
}
