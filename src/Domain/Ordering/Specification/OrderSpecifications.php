<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Specification;

use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\Policy\OrderState;
use Rasuvaeff\Specification\Specification;
use Rasuvaeff\Specification\SpecificationBuilder;

/**
 * The questions asked of the order store, named. Repositories and read
 * models apply them; nobody writes a WHERE for orders by hand.
 */
final readonly class OrderSpecifications
{
    public static function byId(OrderId $id): Specification
    {
        return SpecificationBuilder::create()->whereEqual(column: 'id', value: $id->value)->build();
    }

    /**
     * @param 'amount'|'recent' $sort
     * @param int<1, 100> $limit
     */
    public static function listing(?OrderStatus $status, ?int $minAmountCents, ?string $customerId, string $sort, int $limit, ?OrderState $state = null): Specification
    {
        $builder = SpecificationBuilder::create();
        if ($status instanceof OrderStatus) {
            $builder = $builder->whereEqual(column: 'status', value: $status->value);
        }
        if ($minAmountCents !== null) {
            $builder = $builder->whereGreaterThanOrEqual(column: 'amount_cents', value: $minAmountCents);
        }
        if ($customerId !== null) {
            $builder = $builder->whereEqual(column: 'customer_id', value: $customerId);
        }

        $listing = $builder
            ->orderBy(columns: $sort === 'recent' ? ['created_at' => SORT_DESC, 'id' => SORT_ASC] : ['amount_cents' => SORT_DESC])
            ->limit(limit: $limit)
            ->build();

        // The same policy object the aggregate checks in memory.
        return $state instanceof OrderState ? $listing->withSpecification(specification: $state->policy()) : $listing;
    }
}
