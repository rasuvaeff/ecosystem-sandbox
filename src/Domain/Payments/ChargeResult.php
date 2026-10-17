<?php

declare(strict_types=1);

namespace App\Domain\Payments;

enum ChargeResult
{
    case Approved;
    case Declined;
}
