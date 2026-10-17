<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use Rasuvaeff\Yii3FeatureFlags\ConfigFlagProvider;
use Rasuvaeff\Yii3FeatureFlags\WritableFlagProvider;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\MigrationInterface;

/**
 * The flag table is the one source the checkout and the admin page share.
 * Params (and PAYMENTS_ENABLED) give each flag its first value, once; from
 * then on /admin/flags decides, and a flag already in the table keeps what
 * an operator set.
 */
final readonly class M261017000001SeedFeatureFlags implements MigrationInterface
{
    public function __construct(
        private WritableFlagProvider $flags,
        private ConfigFlagProvider $seedFlags,
    ) {}

    public function up(MigrationBuilder $b): void
    {
        $stored = $this->flags->getFlags();
        foreach ($this->seedFlags->getFlags() as $name => $flag) {
            if (!isset($stored[$name])) {
                $this->flags->save(flag: $flag);
            }
        }
    }
}
