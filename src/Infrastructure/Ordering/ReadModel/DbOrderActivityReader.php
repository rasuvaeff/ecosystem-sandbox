<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\ReadModel;

use App\Application\Ordering\GetOrderEvidence\AuditEntry;
use App\Application\Ordering\GetOrderEvidence\OrderActivityReader;
use App\Application\Ordering\GetOrderEvidence\OutboxEntry;
use App\Domain\Ordering\OrderId;
use App\Domain\Shared\TableNames;
use App\Infrastructure\Ordering\Audit\AuditOrderStatusChanges;
use App\Infrastructure\Shared\Audit\AuditChangeSet;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class DbOrderActivityReader implements OrderActivityReader
{
    public function __construct(
        private ConnectionInterface $db,
        private RetryPolicy $retryPolicy,
    ) {}

    public function audit(OrderId $orderId): array
    {
        $entries = [];
        /** @var array{action: string, request_id: ?string, occurred_at: string, changes: string} $row */
        foreach (new Query(db: $this->db)
            ->select(columns: ['action', 'request_id', 'occurred_at', 'changes'])
            ->from(tables: TableNames::AUDIT_LOG)
            ->where(condition: ['subject_type' => AuditOrderStatusChanges::SUBJECT_TYPE, 'subject_id' => $orderId->value])
            ->orderBy(columns: ['occurred_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all() as $row) {
            $entries[] = new AuditEntry(action: $row['action'], requestId: $row['request_id'], at: $row['occurred_at'], changes: AuditChangeSet::parse(json: $row['changes']));
        }

        return $entries;
    }

    public function outbox(OrderId $orderId): array
    {
        $entries = [];
        /** @var array{type: string, status: string, attempts: int|string, created_at: string, last_attempt_at: ?string} $row */
        foreach (new Query(db: $this->db)
            ->select(columns: ['type', 'status', 'attempts', 'created_at', 'last_attempt_at'])
            ->from(tables: TableNames::OUTBOX)
            ->where(condition: ['aggregate_id' => $orderId->value])
            ->orderBy(columns: ['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all() as $row) {
            $entries[] = new OutboxEntry(type: $row['type'], status: $row['status'], attempts: (int) $row['attempts'], createdAt: $row['created_at'], lastAttemptAt: $row['last_attempt_at']);
        }

        return $entries;
    }

    public function maxAttempts(): int
    {
        return $this->retryPolicy->getMaxAttempts();
    }
}
