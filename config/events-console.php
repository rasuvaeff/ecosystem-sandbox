<?php

declare(strict_types=1);

use Rasuvaeff\Yii3TelemetryOtel\ConsoleCommandSpanListener;
use Yiisoft\Yii\Console\Event\ApplicationShutdown;
use Yiisoft\Yii\Console\Event\ApplicationStartup;

return [
    ApplicationStartup::class => [[ConsoleCommandSpanListener::class, 'onStartup']],
    ApplicationShutdown::class => [[ConsoleCommandSpanListener::class, 'onShutdown']],
];
