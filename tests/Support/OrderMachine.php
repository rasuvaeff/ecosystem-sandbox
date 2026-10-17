<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Ordering\Policy\OrderPolicies;
use App\Domain\Ordering\Refusal\TransitionRefused;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;
use Testo\Assert;

/**
 * The Order aggregate against a model that is only its status: any
 * transition may be tried at any time, the aggregate must apply exactly the
 * ones whose source is the current status, refuse the rest without a trace,
 * and record exactly one OrderStatusChanged per applied transition.
 */
final class OrderMachine
{
    private OrderStatus $model = OrderStatus::Pending;

    private bool $settledOnce = false;

    private readonly Order $order;

    public function __construct()
    {
        $this->order = Order::place(
            id: new OrderId(value: 'machine-1'),
            customerId: new CustomerId(value: 'customer-1'),
            amount: new Amount(cents: 1999),
        );
        // OrderPlaced belongs to placing, not to the lifecycle.
        $this->order->releaseEvents();
    }

    #[Rule]
    public function tryTransition(OrderTransition $transition): void
    {
        $from = $this->model;
        $allowed = $transition->source() === $from;

        $result = $this->order->apply(transition: $transition);
        Assert::same($result->isOk(), $allowed);
        if ($result->isErr()) {
            // A refusal is a value naming the transition and the status it
            // met, and it changes nothing.
            $refused = $result->error();
            Assert::instanceOf($refused, TransitionRefused::class);
            \assert($refused instanceof TransitionRefused);
            Assert::same($refused->transition, $transition);
            Assert::same($refused->status, $from);
            Assert::same($this->order->releaseEvents(), []);

            return;
        }
        Assert::same($result->value(), $transition->target());
        $this->model = $transition->target();
        $this->settledOnce = $this->settledOnce || in_array(needle: $this->model, haystack: [OrderStatus::Paid, OrderStatus::PaymentFailed], strict: true);

        $events = $this->order->releaseEvents();
        Assert::same(count($events), expected: 1);
        Assert::instanceOf($events[0], OrderStatusChanged::class);
        \assert($events[0] instanceof OrderStatusChanged);
        Assert::same($events[0]->transition, $transition);
        Assert::same($events[0]->from, $from);
        Assert::same($events[0]->to, $this->model);
    }

    #[Invariant]
    public function statusFollowsTheModel(): void
    {
        Assert::same($this->order->status(), $this->model);
    }

    /**
     * A paid or failed order never leaves the settled set: its one move left,
     * the recovery, goes from failed to paid.
     */
    #[Invariant]
    public function aSettledOrderStaysSettled(): void
    {
        if ($this->settledOnce) {
            Assert::true($this->order->satisfies(policy: OrderPolicies::settled()));
        }
    }

    /**
     * The policies, evaluated in memory on the aggregate, agree with the
     * model's status at every step: settled is terminal, open is its exact
     * negation, payable is pending alone.
     */
    #[Invariant]
    public function thePoliciesAgreeWithTheModel(): void
    {
        $settled = in_array(needle: $this->model, haystack: [OrderStatus::Paid, OrderStatus::PaymentFailed], strict: true);
        Assert::same($this->order->satisfies(policy: OrderPolicies::settled()), $settled);
        Assert::same($this->order->satisfies(policy: OrderPolicies::open()), !$settled);
        Assert::same($this->order->satisfies(policy: OrderPolicies::payable()), $this->model === OrderStatus::Pending);
    }
}
