<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Assistant\ShowTranscript\Data;

use App\Application\Assistant\GetTranscript\AuditedCall;
use App\Application\Assistant\GetTranscript\TranscriptStep;

/**
 * The in-process MCP conversation about one order, per identity.
 */
final readonly class TranscriptResult implements \JsonSerializable
{
    /**
     * @param list<array{user: string, permitted: bool, steps: list<TranscriptStep>, audit: list<AuditedCall>}> $identities
     */
    public function __construct(
        public string $orderId,
        public array $identities,
    ) {}

    /**
     * @return array{orderId: string, identities: list<array{user: string, permitted: bool, steps: list<TranscriptStep>, audit: list<AuditedCall>}>}
     */
    public function jsonSerialize(): array
    {
        return ['orderId' => $this->orderId, 'identities' => $this->identities];
    }
}
