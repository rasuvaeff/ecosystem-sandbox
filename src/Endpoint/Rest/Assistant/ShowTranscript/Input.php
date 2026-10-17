<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Assistant\ShowTranscript;

use App\Application\Assistant\GetTranscript\GetTranscriptQuery;
use App\Domain\Ordering\OrderId;
use Yiisoft\Input\Http\AbstractInput;
use Yiisoft\Input\Http\Attribute\Data\FromQuery;

/**
 * The order the agents are asked about. An id no order can have is asked
 * about anyway, as an order that does not exist: the transcript then shows
 * the agents answering `found: false`.
 */
#[FromQuery]
final class Input extends AbstractInput
{
    private const string NO_SUCH_ORDER = 'no-such-order';

    public string $orderId = '';

    /**
     * @return non-empty-string
     */
    public function orderId(): string
    {
        return OrderId::tryFrom(value: $this->orderId)?->value ?? self::NO_SUCH_ORDER;
    }

    public function createQuery(): GetTranscriptQuery
    {
        return new GetTranscriptQuery(orderId: $this->orderId());
    }
}
