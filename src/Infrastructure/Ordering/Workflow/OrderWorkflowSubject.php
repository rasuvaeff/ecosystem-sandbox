<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Workflow;

use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderStatus;
use Rasuvaeff\Yii3Workflow\SubjectIdentity;

/**
 * What yii3-workflow moves: a carrier of the order's id and status. The
 * marking store writes `status` here, never into the aggregate, which changes
 * only through Order::apply().
 *
 * @internal
 */
final class OrderWorkflowSubject implements SubjectIdentity
{
    public OrderStatus $status;

    public function __construct(private readonly Order $order)
    {
        $this->status = $order->status();
    }

    public function workflowSubjectId(): string
    {
        return $this->order->id()->value;
    }
}
