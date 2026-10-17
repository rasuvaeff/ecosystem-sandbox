<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * What the bank holds for a charge, asked after the outcome was lost.
 */
enum ChargeFinding
{
    case Charged;
    case NotCharged;
}
