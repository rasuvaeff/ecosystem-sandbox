<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Workflow;

use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Ordering\Refusal\TransitionRefused;
use Rasuvaeff\Yii3Workflow\IdempotentWorkflow;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransaction;

/**
 * The order lifecycle on yii3-workflow (the guards of the state machine) and
 * yii3-workflow-db (the transition log whose unique key makes a transition
 * happen once per idempotency key, under concurrency too).
 */
final readonly class WorkflowOrderLifecycle implements OrderLifecycle
{
    public const string WORKFLOW = 'order';

    public function __construct(
        private IdempotentWorkflow $workflow,
        private WorkflowTransaction $transaction,
    ) {}

    public function apply(Order $order, OrderTransition $transition, string $idempotencyKey, callable $then): bool
    {
        return $this->transaction->applyOnce(
            workflow: $this->workflow,
            subject: new OrderWorkflowSubject(order: $order),
            transitionName: $transition->value,
            idempotencyKey: $idempotencyKey,
            then: static function () use ($order, $transition, $then): void {
                // The workflow has just allowed the transition from this
                // status; the aggregate refusing it now is a broken
                // invariant, not a business answer.
                $order->apply(transition: $transition)->match(
                    ok: static fn (OrderStatus $status): OrderStatus => $status,
                    err: static fn (TransitionRefused $refused): never => throw new \LogicException(message: 'The workflow allowed what the order refuses: ' . $refused->reason()),
                );
                $then();
            },
        );
    }

    public function possibleTransitions(Order $order): array
    {
        $transitions = [];
        foreach ($this->workflow->enabledTransitions(new OrderWorkflowSubject(order: $order)) as $transition) {
            $transitions[] = OrderTransition::from(value: $transition->getName());
        }

        return $transitions;
    }
}
