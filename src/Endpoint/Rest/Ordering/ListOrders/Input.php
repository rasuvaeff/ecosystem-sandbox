<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ListOrders;

use App\Application\Ordering\ListOrders\ListOrdersQuery;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\Policy\OrderState;
use Yiisoft\Input\Http\AbstractInput;
use Yiisoft\Input\Http\Attribute\Data\FromQuery;

/**
 * Whitelisted list filters from the query string. They are taken as the
 * strings the client sent and normalised in createQuery(): a filter that
 * does not parse is ignored rather than refused, so no rule rejects one.
 */
#[FromQuery]
final class Input extends AbstractInput
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 100;

    public string $status = '';

    public string $minAmountCents = '';

    public string $customerId = '';

    public string $sort = '';

    public string $limit = '';

    public string $state = '';

    public function createQuery(): ListOrdersQuery
    {
        return new ListOrdersQuery(
            status: OrderStatus::tryFrom(value: $this->status),
            minAmountCents: $this->digits(value: $this->minAmountCents),
            customerId: $this->customerId !== '' && mb_strlen(string: $this->customerId) <= CustomerId::MAX_LENGTH ? $this->customerId : null,
            sort: $this->sort === 'recent' ? 'recent' : 'amount',
            limit: min(self::MAX_LIMIT, max(1, $this->digits(value: $this->limit) ?? self::DEFAULT_LIMIT)),
            state: OrderState::tryFrom(value: $this->state),
        );
    }

    /**
     * @return int<0, max>|null
     */
    private function digits(string $value): ?int
    {
        return ctype_digit(text: $value) ? max(0, (int) $value) : null;
    }
}
