<?php

declare(strict_types=1);

namespace App\Application\Assistant\GetTranscript;

use App\Domain\Assistant\AgentIdentities;
use Psr\Clock\ClockInterface;

/**
 * The same questions asked by an agent with the support role and by one
 * without, each followed by what the audit trail recorded for its calls. The
 * tools are read-only; the one thing a conversation writes is what any MCP
 * call writes — its audit row.
 */
final readonly class GetTranscriptHandler
{
    public function __construct(
        private AgentIdentities $identities,
        private AgentConversation $conversation,
        private ToolCallAudit $audit,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<array{user: string, permitted: bool, steps: list<TranscriptStep>, audit: list<AuditedCall>}>
     */
    public function handle(GetTranscriptQuery $query): array
    {
        $since = $this->clock->now()->setTimezone(timezone: new \DateTimeZone(timezone: 'UTC'))->format(format: 'Y-m-d H:i:s');
        $transcripts = [];
        foreach ($this->identities->all() as $userId) {
            $steps = $this->conversation->converse(userId: $userId, orderId: $query->orderId);
            $calls = array_slice(array: $steps, offset: 1);
            $audit = $this->audit->latestFor(userId: $userId, since: $since, limit: max(1, count(value: $calls)));
            // Rows written within one second have no order of their own; the
            // calls were made in step order, so that is the order shown.
            $position = array_flip(array: array_map(callback: static fn (TranscriptStep $step): string => $step->call, array: $calls));
            usort($audit, static fn (AuditedCall $a, AuditedCall $b): int => ($position[$a->tool] ?? PHP_INT_MAX) <=> ($position[$b->tool] ?? PHP_INT_MAX));
            $transcripts[] = [
                'user' => $userId,
                'permitted' => $userId === $this->identities->permitted,
                'steps' => $steps,
                'audit' => $audit,
            ];
        }

        return $transcripts;
    }
}
