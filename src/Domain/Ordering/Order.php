<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Ordering\Event\OrderPlaced;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\Policy\OrderPolicies;
use App\Domain\Ordering\Refusal\TransitionRefused;
use App\Domain\Shared\Event\DomainEvent;
use App\Domain\Shared\Specification\InMemorySpecification;
use Rasuvaeff\Result\Result;
use Rasuvaeff\Specification\Specification;

/**
 * The order aggregate. Its status changes only through a transition of the
 * lifecycle (OrderTransition); every change and the placing itself are
 * recorded as domain events, released once by the handler that saves it.
 */
final class Order
{
    /** @var list<DomainEvent> */
    private array $events = [];

    private function __construct(
        private readonly OrderId $id,
        private readonly CustomerId $customerId,
        private readonly Amount $amount,
        private OrderStatus $status,
    ) {}

    public static function place(OrderId $id, CustomerId $customerId, Amount $amount): self
    {
        $order = new self(id: $id, customerId: $customerId, amount: $amount, status: OrderStatus::Pending);
        $order->events[] = new OrderPlaced(orderId: $id, customerId: $customerId, amount: $amount);

        return $order;
    }

    /**
     * Rebuilds a stored order; records nothing.
     */
    public static function restore(OrderId $id, CustomerId $customerId, Amount $amount, OrderStatus $status): self
    {
        return new self(id: $id, customerId: $customerId, amount: $amount, status: $status);
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function amount(): Amount
    {
        return $this->amount;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    /**
     * An order id names one purchase: a replay must carry the same payload,
     * otherwise the stored order would be charged for a request the client
     * did not send.
     */
    public function isPlacedAs(CustomerId $customerId, Amount $amount): bool
    {
        return $this->customerId->equals($customerId) && $this->amount->equals($amount);
    }

    /**
     * Checks a policy (OrderPolicies) against this order, with the same
     * column names the read models query.
     */
    public function satisfies(Specification $policy): bool
    {
        return InMemorySpecification::satisfies(specification: $policy, row: [
            'id' => $this->id->value,
            'customer_id' => $this->customerId->value,
            'amount_cents' => $this->amount->cents,
            'status' => $this->status->value,
        ]);
    }

    /**
     * @return Result<OrderStatus, TransitionRefused> the new status, or why the transition was refused
     */
    #[\NoDiscard('A refused transition is a value; ignoring it loses the refusal')]
    public function apply(OrderTransition $transition): Result
    {
        if (!$this->satisfies(policy: OrderPolicies::canTake(transition: $transition))) {
            return Result::err(new TransitionRefused(orderId: $this->id, transition: $transition, status: $this->status));
        }
        $from = $this->status;
        $this->status = $transition->target();
        $this->events[] = new OrderStatusChanged(orderId: $this->id, amount: $this->amount, transition: $transition, from: $from, to: $this->status);

        return Result::ok($this->status);
    }

    /**
     * @return list<DomainEvent> the events recorded since the last release
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
