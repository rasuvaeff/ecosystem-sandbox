<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Audit;

use Rasuvaeff\Schema\Schema;

/**
 * The `changes` column of an `audit_log` row (yii3-audit-log-db stores the
 * change set as JSON). A malformed one reads as no changes rather than
 * failing the whole read.
 */
final class AuditChangeSet
{
    /**
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    public static function parse(string $json): array
    {
        $parsed = Schema::arrayOf(Schema::object([
            'field' => Schema::string(),
            'old' => Schema::json()->optional(),
            'new' => Schema::json()->optional(),
        ])->strip())->parse(input: json_decode(json: $json, associative: true));
        if (!$parsed->ok) {
            return [];
        }
        /** @var list<array{field: string, old?: mixed, new?: mixed}> $changes */
        $changes = $parsed->value;

        return array_map(
            callback: static fn (array $change): array => ['field' => $change['field'], 'old' => $change['old'] ?? null, 'new' => $change['new'] ?? null],
            array: $changes,
        );
    }
}
