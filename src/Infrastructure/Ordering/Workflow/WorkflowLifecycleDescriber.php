<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Workflow;

use App\Application\Ordering\DescribeLifecycle\LifecycleDescriber;
use App\Application\Ordering\DescribeLifecycle\LifecycleDescription;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;

final readonly class WorkflowLifecycleDescriber implements LifecycleDescriber
{
    public function __construct(private WorkflowRegistry $registry) {}

    public function describe(): LifecycleDescription
    {
        $definition = $this->registry->get(WorkflowOrderLifecycle::WORKFLOW)->definition();
        $transitions = [];
        foreach ($definition->getTransitions() as $transition) {
            /** @var list<string> $from */
            $from = array_values(array: $transition->getFroms());
            /** @var list<string> $to */
            $to = array_values(array: $transition->getTos());
            $transitions[] = ['name' => $transition->getName(), 'from' => $from, 'to' => $to];
        }

        return new LifecycleDescription(
            name: WorkflowOrderLifecycle::WORKFLOW,
            initial: array_values(array: $definition->getInitialPlaces()),
            places: array_values(array: $definition->getPlaces()),
            transitions: $transitions,
        );
    }
}
