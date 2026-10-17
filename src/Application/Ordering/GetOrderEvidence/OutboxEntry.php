<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderEvidence;

final readonly class OutboxEntry implements \JsonSerializable
{
    public function __construct(
        public string $type,
        public string $status,
        public int $attempts,
        public string $createdAt,
        public ?string $lastAttemptAt,
    ) {}

    /**
     * @return array{type: string, status: string, attempts: int, createdAt: string, lastAttemptAt: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'status' => $this->status, 'attempts' => $this->attempts, 'createdAt' => $this->createdAt, 'lastAttemptAt' => $this->lastAttemptAt];
    }
}
