<?php

declare(strict_types=1);

namespace App\Domain\Marketing;

/**
 * The A/B goal a placed order converts: the checkout credits it, the
 * analytics report counts it.
 */
final class CheckoutConversion
{
    public const string GOAL = 'checkout';
}
