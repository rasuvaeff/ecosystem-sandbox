<?php

declare(strict_types=1);

namespace App\Application\Assistant\GetTranscript;

interface ToolCallAudit
{
    /**
     * The newest tool calls of $userId since $since (UTC, `Y-m-d H:i:s`), oldest first.
     *
     * @param positive-int $limit
     *
     * @return list<AuditedCall>
     */
    public function latestFor(string $userId, string $since, int $limit): array;
}
