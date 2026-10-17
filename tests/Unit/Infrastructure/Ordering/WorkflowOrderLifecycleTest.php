<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Ordering;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderLifecycle;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\OrderTransition;
use App\Infrastructure\Ordering\Workflow\WorkflowOrderLifecycle;
use Psr\Container\ContainerInterface;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The adapter's property: yii3-workflow, configured from params through the
 * real container, allows exactly what the domain's transition table says —
 * it neither loses a transition nor invents one.
 */
#[Test]
#[Covers(WorkflowOrderLifecycle::class)]
final class WorkflowOrderLifecycleTest
{
    #[Property(runs: 20, auto: true)]
    public function theWorkflowAllowsExactlyTheDomainsTransitions(OrderStatus $status): void
    {
        $order = Order::restore(id: new OrderId(value: 'guard-1'), customerId: new CustomerId(value: 'c-1'), amount: new Amount(cents: 1999), status: $status);
        $expected = array_values(array_filter(
            array: OrderTransition::cases(),
            callback: static fn (OrderTransition $transition): bool => $transition->source() === $status,
        ));

        Assert::same($this->lifecycle()->possibleTransitions(order: $order), $expected);
    }

    private function lifecycle(): OrderLifecycle
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, levels: 4) . '/bin/support/container.php';
        $lifecycle = $container->get(OrderLifecycle::class);
        \assert($lifecycle instanceof OrderLifecycle);

        return $lifecycle;
    }
}
