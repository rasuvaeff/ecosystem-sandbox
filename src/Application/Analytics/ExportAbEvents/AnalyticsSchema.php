<?php

declare(strict_types=1);

namespace App\Application\Analytics\ExportAbEvents;

/**
 * The analytics store's tables for A/B events, created once before the first
 * export.
 */
interface AnalyticsSchema
{
    public function isInstalled(): bool;

    public function install(): void;
}
