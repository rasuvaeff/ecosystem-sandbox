<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Checkout;

use App\Application\Ordering\PlaceOrder\PlaceOrderCommand;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;
use Yiisoft\Input\Http\AbstractInput;
use Yiisoft\Input\Http\Attribute\Data\FromBody;
use Yiisoft\Validator\Rule\Integer;
use Yiisoft\Validator\Rule\Length;

/**
 * The checkout body, hydrated and validated by yiisoft/input-http before the
 * action runs; the bounds are the domain's own. The OpenAPI document is
 * checked first, so a body this rejects is one the document let through —
 * CheckoutInputPropertyTest keeps the two equal.
 *
 * No #[Required]: it counts a whitespace-only string as empty, while the
 * document and the domain accept an id such as " " (property-found). Length
 * with min 1 is the document's minLength, and a missing field keeps the empty
 * default, which Length refuses.
 */
#[FromBody]
final class Input extends AbstractInput
{
    #[Length(min: 1, max: OrderId::MAX_LENGTH)]
    public string $orderId = '';

    #[Length(min: 1, max: CustomerId::MAX_LENGTH)]
    public string $customerId = '';

    #[Integer(min: Amount::MIN_CENTS, max: Amount::MAX_CENTS)]
    public int $amountCents = 0;

    /**
     * @param non-empty-string $idempotencyKey
     */
    public function createCommand(string $idempotencyKey): PlaceOrderCommand
    {
        return new PlaceOrderCommand(
            orderId: new OrderId(value: $this->orderId),
            customerId: new CustomerId(value: $this->customerId),
            amount: new Amount(cents: $this->amountCents),
            idempotencyKey: $idempotencyKey,
        );
    }
}
