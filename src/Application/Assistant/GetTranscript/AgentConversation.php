<?php

declare(strict_types=1);

namespace App\Application\Assistant\GetTranscript;

/**
 * An AI agent talking to the shop over MCP as one identity: it lists the
 * tools it can see, then asks about one order and the payment gateway.
 */
interface AgentConversation
{
    /**
     * @param non-empty-string $userId
     *
     * @return list<TranscriptStep> the tools/list step first
     */
    public function converse(string $userId, string $orderId): array;
}
