<?php

declare(strict_types=1);

namespace App\Application\Ordering\PlaceOrder;

use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderRepository;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Ordering\Policy\OrderPolicies;
use App\Domain\Ordering\Refusal\AlreadySettled;
use App\Domain\Ordering\Refusal\PayloadConflict;
use App\Domain\Shared\Transaction\TransactionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Result\Result;

/**
 * Places an order and starts its payment, in one transaction: the order row,
 * the lifecycle transition, and everything the released events cause (the
 * payment request in the outbox, the audit row, the campaign attribution)
 * commit together or not at all.
 */
final readonly class PlaceOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrderLifecycle $lifecycle,
        private TransactionManager $transactions,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @return Result<PlaceOrderResult, PayloadConflict|AlreadySettled>
     */
    #[\NoDiscard('A refused checkout is a value; ignoring it loses the refusal')]
    public function handle(PlaceOrderCommand $command): Result
    {
        /** @var Result<PlaceOrderResult, PayloadConflict|AlreadySettled> $result */
        $result = $this->transactions->execute(operation: function () use ($command): Result {
            $order = $this->orders->findForUpdate(id: $command->orderId);
            if (!$order instanceof Order) {
                $placed = Order::place(id: $command->orderId, customerId: $command->customerId, amount: $command->amount);
                // A concurrent request may have inserted it first: only the
                // request that placed the order publishes its placing.
                if ($this->orders->addIfAbsent(order: $placed)) {
                    $this->release(order: $placed);
                }
                $order = $this->orders->findForUpdate(id: $command->orderId);
            }
            if (!$order instanceof Order) {
                return Result::ok(new PlaceOrderResult(orderId: $command->orderId, status: OrderStatus::Pending, accepted: false));
            }
            if (!$order->isPlacedAs(customerId: $command->customerId, amount: $command->amount)) {
                return Result::err(new PayloadConflict(orderId: $order->id()));
            }
            if ($order->satisfies(policy: OrderPolicies::settled())) {
                // The contract promises `status: processing` on a 202; an
                // order that is paid or failed cannot honestly be accepted again.
                return Result::err(new AlreadySettled(orderId: $order->id(), status: $order->status()));
            }

            $accepted = $order->satisfies(policy: OrderPolicies::payable()) && $this->lifecycle->apply(
                order: $order,
                transition: OrderTransition::StartPayment,
                idempotencyKey: $command->idempotencyKey,
                then: function () use ($order): void {
                    $this->orders->save(order: $order);
                    $this->release(order: $order);
                },
            );

            return Result::ok(new PlaceOrderResult(orderId: $order->id(), status: $order->status(), accepted: $accepted));
        });

        return $result;
    }

    private function release(Order $order): void
    {
        foreach ($order->releaseEvents() as $event) {
            $this->events->dispatch(event: $event);
        }
    }
}
