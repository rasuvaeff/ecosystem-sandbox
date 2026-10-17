<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Policy;

use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use Rasuvaeff\Specification\NotSpecification;
use Rasuvaeff\Specification\Specification;
use Rasuvaeff\Specification\SpecificationBuilder;

/**
 * The order's business rules as specifications over its columns, each one
 * written once: the aggregate checks them in memory (Order::satisfies) and
 * the read models translate the same object into SQL.
 */
final readonly class OrderPolicies
{
    /**
     * A payment ended, one way or the other: checkout never starts it again.
     * The one move left is the reconciliation's: a failed order whose money
     * the bank turns out to have taken becomes paid (RecoverPayment).
     */
    public static function settled(): Specification
    {
        return SpecificationBuilder::create()
            ->whereIn(column: 'status', values: [OrderStatus::Paid->value, OrderStatus::PaymentFailed->value])
            ->build();
    }

    /**
     * Placed and not yet sent to the bank: the only order a checkout starts
     * a payment for.
     */
    public static function payable(): Specification
    {
        return SpecificationBuilder::create()->whereEqual(column: 'status', value: OrderStatus::Pending->value)->build();
    }

    /**
     * Not settled yet: pending or with the bank. The negation of settled(),
     * so the two can never overlap or leave a gap.
     */
    public static function open(): Specification
    {
        return new NotSpecification(specification: self::settled());
    }

    /**
     * The order is in the status the transition starts from.
     */
    public static function canTake(OrderTransition $transition): Specification
    {
        return SpecificationBuilder::create()->whereEqual(column: 'status', value: $transition->source()->value)->build();
    }
}
