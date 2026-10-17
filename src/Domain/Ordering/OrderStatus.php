<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case PaymentFailed = 'payment_failed';
}
