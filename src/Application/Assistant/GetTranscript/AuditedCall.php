<?php

declare(strict_types=1);

namespace App\Application\Assistant\GetTranscript;

/**
 * One audit row yii3-mcp-audit-log-bridge wrote for a tool call.
 */
final readonly class AuditedCall implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $tool,
        public string $outcome,
        public int $durationMs,
        public string $at,
        public object $arguments,
    ) {}

    /**
     * @return array{id: string, tool: string, outcome: string, durationMs: int, at: string, arguments: object}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'tool' => $this->tool, 'outcome' => $this->outcome, 'durationMs' => $this->durationMs, 'at' => $this->at, 'arguments' => $this->arguments];
    }
}
