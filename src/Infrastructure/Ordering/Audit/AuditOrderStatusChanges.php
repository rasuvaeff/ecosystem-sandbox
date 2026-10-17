<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Audit;

use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Infrastructure\Shared\Correlation\CurrentRequestId;
use Rasuvaeff\Yii3AuditLog\AuditActor;
use Rasuvaeff\Yii3AuditLog\AuditChangeSet;
use Rasuvaeff\Yii3AuditLog\AuditLogger;
use Rasuvaeff\Yii3AuditLog\AuditMetadata;
use Rasuvaeff\Yii3AuditLog\AuditSubject;

/**
 * Every status change is an audit row (yii3-audit-log), written in the
 * transaction of the change and filed under the correlation id of the
 * request or the worker run that caused it.
 */
final readonly class AuditOrderStatusChanges
{
    public const string SUBJECT_TYPE = 'order';

    public function __construct(
        private AuditLogger $audit,
        private CurrentRequestId $requestId,
    ) {}

    public function __invoke(OrderStatusChanged $event): void
    {
        $this->audit->logChange(
            actor: AuditActor::system(),
            subject: AuditSubject::of(type: self::SUBJECT_TYPE, id: $event->orderId->value),
            changes: AuditChangeSet::fromArrays(old: ['status' => $event->from->value], new: ['status' => $event->to->value]),
            metadata: new AuditMetadata(requestId: $this->requestId->get()),
        );
    }
}
