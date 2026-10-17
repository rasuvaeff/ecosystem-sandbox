<?php

declare(strict_types=1);

use App\Endpoint\Console\Operations\SettingsCommand;
use App\Endpoint\Console\Payments\PaymentWorkerCommand;
use Rasuvaeff\Yii3UtmDb\Command\PurgeUtmAttributionCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'sandbox:outbox:run' => PaymentWorkerCommand::class,
            'sandbox:projections:run' => App\Endpoint\Console\Ordering\ResultSubscribersCommand::class,
            'sandbox:ab:export' => App\Endpoint\Console\Analytics\AbExportCommand::class,
            'sandbox:settings:show' => SettingsCommand::class,
            'utm:purge' => PurgeUtmAttributionCommand::class,
        ],
    ],
];
