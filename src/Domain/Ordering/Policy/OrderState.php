<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Policy;

use Rasuvaeff\Specification\Specification;

/**
 * Where an order is in its life, as a filter: each state is a policy.
 */
enum OrderState: string
{
    case Open = 'open';
    case Settled = 'settled';

    public function policy(): Specification
    {
        return match ($this) {
            self::Open => OrderPolicies::open(),
            self::Settled => OrderPolicies::settled(),
        };
    }
}
