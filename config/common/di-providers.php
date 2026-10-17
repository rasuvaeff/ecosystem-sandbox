<?php

declare(strict_types=1);

use App\Bootloader\AnalyticsBootloader;
use App\Bootloader\AssistantBootloader;
use App\Bootloader\OrderingBootloader;
use App\Bootloader\PaymentsBootloader;
use App\Bootloader\SharedBootloader;

return [
    SharedBootloader::class,
    OrderingBootloader::class,
    PaymentsBootloader::class,
    AssistantBootloader::class,
    AnalyticsBootloader::class,
];
