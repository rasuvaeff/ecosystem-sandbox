<?php

declare(strict_types=1);

namespace App\Infrastructure\Assistant\Audit;

use App\Application\Assistant\GetTranscript\AuditedCall;
use App\Application\Assistant\GetTranscript\ToolCallAudit;
use App\Domain\Shared\TableNames;
use App\Infrastructure\Shared\Audit\AuditChangeSet;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * The audit rows yii3-mcp-audit-log-bridge wrote for one identity's tool
 * calls, read back from the same `audit_log` table the order evidence uses.
 */
final readonly class DbToolCallAudit implements ToolCallAudit
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function latestFor(string $userId, string $since, int $limit): array
    {
        $calls = [];
        /** @var array{id: int|string, subject_id: string, occurred_at: string, changes: string} $row */
        foreach (new Query(db: $this->db)
            ->select(columns: ['id', 'subject_id', 'occurred_at', 'changes'])
            ->from(tables: TableNames::AUDIT_LOG)
            ->where(condition: ['action' => 'mcp.tools.call', 'actor_id' => $userId])
            ->andWhere(condition: ['>=', 'occurred_at', $since])
            ->orderBy(columns: ['occurred_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(limit: $limit)
            ->all() as $row) {
            // The bridge writes the outcome and the duration as `mcp.*`
            // fields next to the call's own arguments.
            $values = array_column(array: AuditChangeSet::parse(json: $row['changes']), column_key: 'new', index_key: 'field');
            $arguments = array_filter(
                array: $values,
                callback: static fn (string $field): bool => !str_starts_with(haystack: $field, needle: 'mcp.'),
                mode: ARRAY_FILTER_USE_KEY,
            );
            $calls[] = new AuditedCall(
                id: (string) $row['id'],
                tool: $row['subject_id'],
                outcome: $this->stringOf(value: $values['mcp.outcome'] ?? null),
                durationMs: $this->intOf(value: $values['mcp.duration_ms'] ?? null),
                at: $row['occurred_at'],
                // An object even when empty: a call without arguments is `{}`, not `[]`.
                arguments: (object) $arguments,
            );
        }

        return array_reverse(array: $calls);
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function intOf(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
