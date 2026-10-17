<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\IssueRealtimeToken\Data;

/**
 * A Centrifugo connection token for one order channel, and where to connect.
 */
final readonly class RealtimeTokenResult implements \JsonSerializable
{
    public function __construct(
        public string $channel,
        #[\SensitiveParameter]
        public string $token,
        public int $port,
        public string $path,
    ) {}

    /**
     * @return array{channel: string, token: string, port: int, path: string}
     */
    public function jsonSerialize(): array
    {
        return ['channel' => $this->channel, 'token' => $this->token, 'port' => $this->port, 'path' => $this->path];
    }
}
