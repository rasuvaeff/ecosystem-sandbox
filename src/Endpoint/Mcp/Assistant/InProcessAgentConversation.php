<?php

declare(strict_types=1);

namespace App\Endpoint\Mcp\Assistant;

use App\Application\Assistant\GetTranscript\AgentConversation;
use App\Application\Assistant\GetTranscript\TranscriptStep;

/**
 * The conversation runs in-process against the real MCP server (AgentFactory),
 * never through /mcp, so the endpoint's shared secret stays out of the browser.
 */
final readonly class InProcessAgentConversation implements AgentConversation
{
    public function __construct(private AgentFactory $agents) {}

    public function converse(string $userId, string $orderId): array
    {
        $agent = $this->agents->as(userId: $userId);
        $agent->initialize();
        $steps = [new TranscriptStep(
            call: 'tools/list',
            arguments: null,
            refused: false,
            error: false,
            result: array_map(
                callback: static fn (array $tool): array => ['name' => (string) ($tool['name'] ?? ''), 'description' => (string) ($tool['description'] ?? '')],
                array: $agent->listTools(),
            ),
        )];
        foreach ([['order.status', ['orderId' => $orderId]], ['order.timeline', ['orderId' => $orderId]], ['payment.gateway_status', []]] as [$tool, $arguments]) {
            try {
                $result = $agent->callTool(name: $tool, arguments: $arguments);
                // A tool that threw, or an interceptor that denied the call,
                // comes back as a successful JSON-RPC result flagged isError;
                // only a tool the agent cannot even see raises an exception.
                $isError = ($result['isError'] ?? false) === true;
                $steps[] = new TranscriptStep(
                    call: $tool,
                    arguments: $arguments,
                    refused: false,
                    error: $isError,
                    result: $isError ? (string) ($result['content'][0]['text'] ?? 'tool error') : ($result['structuredContent'] ?? $result['content'] ?? $result),
                );
            } catch (\Throwable $exception) {
                $steps[] = new TranscriptStep(call: $tool, arguments: $arguments, refused: true, error: false, result: $exception->getMessage());
            }
        }

        return $steps;
    }
}
