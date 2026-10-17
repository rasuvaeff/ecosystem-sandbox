<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetOrderEvidence;

final readonly class AuditEntry implements \JsonSerializable
{
    /**
     * @param list<array{field: string, old: mixed, new: mixed}> $changes
     */
    public function __construct(
        public string $action,
        public ?string $requestId,
        public string $at,
        public array $changes,
    ) {}

    public function statusSetTo(): string
    {
        foreach ($this->changes as $change) {
            if ($change['field'] === 'status' && is_string($change['new'])) {
                return $change['new'];
            }
        }

        return '';
    }

    /**
     * @return array{action: string, requestId: ?string, at: string, changes: list<array{field: string, old: mixed, new: mixed}>}
     */
    public function jsonSerialize(): array
    {
        return ['action' => $this->action, 'requestId' => $this->requestId, 'at' => $this->at, 'changes' => $this->changes];
    }
}
