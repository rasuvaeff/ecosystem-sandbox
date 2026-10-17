<?php

declare(strict_types=1);

namespace App\Application\Assistant\GetTranscript;

/**
 * One exchange of the conversation: what the agent asked and what it got.
 * `refused`: the agent could not even see the tool; `error`: the tool, or an
 * interceptor, answered with an error.
 */
final readonly class TranscriptStep implements \JsonSerializable
{
    /**
     * @param array<string, string>|null $arguments
     */
    public function __construct(
        public string $call,
        public ?array $arguments,
        public bool $refused,
        public bool $error,
        public mixed $result,
    ) {}

    /**
     * @return array{call: string, arguments: array<string, string>|null, refused: bool, error: bool, result: mixed}
     */
    public function jsonSerialize(): array
    {
        return ['call' => $this->call, 'arguments' => $this->arguments, 'refused' => $this->refused, 'error' => $this->error, 'result' => $this->result];
    }
}
