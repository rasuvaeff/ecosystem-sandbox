<?php

declare(strict_types=1);

return [
    'config-plugin' => [
        'params' => 'common/params.php',
        'params-web' => ['$params'],
        'params-console' => ['$params', 'console/params.php'],
        'di' => 'common/di/*.php',
        'di-web' => ['$di', 'web/di/*.php'],
        'di-console' => ['$di'],
        // Interface -> implementation bindings, one Bootloader per context.
        'di-providers' => 'common/di-providers.php',
        'di-providers-web' => '$di-providers',
        'di-providers-console' => '$di-providers',
        // Domain events and their in-process handlers.
        'events' => 'common/events.php',
        'events-web' => '$events',
        'events-console' => ['$events', 'events-console.php'],
    ],
    // APP_ENV picks one (App\Environment); each may override params.
    'config-plugin-environments' => [
        'dev' => ['params' => 'environments/dev/params.php'],
        'test' => ['params' => 'environments/test/params.php'],
        'prod' => ['params' => 'environments/prod/params.php'],
    ],
    'config-plugin-options' => ['source-directory' => 'config'],
];
