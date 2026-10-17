<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Queue;

use Predis\Client;
use Yiisoft\Queue\Message\MessageInterface;
use Yiisoft\Queue\Message\Serializer\MessageSerializerInterface;

/**
 * Small Redis Streams transport used by the sandbox messaging profile.
 *
 * PostgreSQL outbox remains the durable source. This transport demonstrates
 * queue delivery semantics after projection: consumer groups, explicit ACK,
 * and reclaim of abandoned pending entries.
 */
final readonly class RedisStreamQueue
{
    public function __construct(
        private Client $redis,
        private MessageSerializerInterface $serializer,
        private string $stream = 'sandbox:queue',
        private string $group = 'sandbox-workers',
    ) {}

    public function ensureGroup(): void
    {
        try {
            $this->redis->executeRaw(['XGROUP', 'CREATE', $this->stream, $this->group, '0', 'MKSTREAM']);
        } catch (\Throwable $exception) {
            if (!str_contains(strtolower($exception->getMessage()), needle: 'busygroup')) {
                throw $exception;
            }
        }
    }

    public function push(MessageInterface $message): string
    {
        return (string) $this->redis->executeRaw([
            'XADD', $this->stream, '*', 'payload', $this->serializer->serialize($message),
        ]);
    }

    /** @return list<array{id: string, message: MessageInterface}> */
    public function read(string $consumer, int $count = 10): array
    {
        return $this->decodeRows(rows: $this->redis->executeRaw(arguments: [
            'XREADGROUP', 'GROUP', $this->group, $consumer, 'COUNT', (string) $count, 'STREAMS', $this->stream, '>',
        ]));
    }

    public function ack(string $id): void
    {
        $this->redis->executeRaw(['XACK', $this->stream, $this->group, $id]);
    }

    /** @return list<array{id: string, message: MessageInterface}> */
    public function reclaim(string $consumer, int $idleMilliseconds = 0, int $count = 10): array
    {
        return $this->decodeRows(rows: $this->redis->executeRaw(arguments: [
            'XAUTOCLAIM', $this->stream, $this->group, $consumer, (string) $idleMilliseconds, '0-0', 'COUNT', (string) $count,
        ]));
    }

    /** @return list<array{id: string, message: MessageInterface}> */
    private function decodeRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        // XREADGROUP returns [[stream, [[id, fields], ...]]], while
        // XAUTOCLAIM returns [next-id, [[id, fields], ...], deleted-ids].
        $candidateRows = [];
        if (isset($rows[1]) && is_array($rows[1]) && is_string($rows[0] ?? null)) {
            $candidateRows = $rows[1];
        } elseif (isset($rows[0]) && is_array($rows[0]) && isset($rows[0][1]) && is_array($rows[0][1])) {
            $candidateRows = $rows[0][1];
        }

        $entries = [];
        foreach ($candidateRows as $row) {
            if (!is_array($row) || count($row) < 2 || !is_array($row[1])) {
                continue;
            }
            // The entry's fields are a flat [name, value, name, value] list.
            $at = array_search(needle: 'payload', haystack: $row[1], strict: true);
            $payload = is_int($at) && $at % 2 === 0 ? $this->stringOrNull(value: $row[1][$at + 1] ?? null) : null;
            if ($payload === null) {
                continue;
            }
            $entries[] = ['id' => (string) $row[0], 'message' => $this->serializer->unserialize($payload)];
        }

        return $entries;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
