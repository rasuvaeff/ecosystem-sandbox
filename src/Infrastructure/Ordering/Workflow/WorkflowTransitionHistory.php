<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Workflow;

use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\TransitionHistory;
use App\Domain\Ordering\TransitionRecord;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;

/**
 * The transition log yii3-workflow-db keeps — the same rows whose unique key
 * makes each transition happen once.
 */
final readonly class WorkflowTransitionHistory implements TransitionHistory
{
    public function __construct(private TransitionLog $transitions) {}

    public function forOrder(OrderId $orderId): array
    {
        $records = [];
        foreach ($this->transitions->forSubject(WorkflowOrderLifecycle::WORKFLOW, $orderId->value) as $record) {
            $records[] = new TransitionRecord(transition: $record->transition, from: $record->from, to: $record->to, at: $record->at);
        }

        return $records;
    }
}
