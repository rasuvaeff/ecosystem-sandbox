<?php

declare(strict_types=1);

use App\Environment;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\JsonRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = dirname(path: __DIR__);

require_once $root . '/src/autoload.php';

// PHP built-in server routing.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url(url: $_SERVER['REQUEST_URI'] ?? '', component: PHP_URL_PATH);
    // Serve static files as is.
    if (is_string(value: $path) && is_file(filename: __DIR__ . $path)) {
        return false;
    }
    // Explicitly set for URLs with dot.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

new HttpApplicationRunner(
    rootPath: $root,
    // Off unless APP_DEBUG says so: in debug mode an error that escapes the
    // problem renderer is answered with a full stack-trace page.
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    diGroup: 'di-web',
    paramsGroup: 'params-web',
    nestedParamsGroups: ['params'],
    // While the container is being built nothing else can report: a broken
    // definition is logged to stderr and answered as JSON.
    temporaryErrorHandler: new ErrorHandler(
        logger: new Logger(targets: [new StreamTarget(stream: 'php://stderr')]),
        defaultRenderer: new JsonRenderer(),
    ),
)->run();
