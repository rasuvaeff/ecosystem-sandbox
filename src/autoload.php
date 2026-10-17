<?php

declare(strict_types=1);

use App\Environment;
use Dotenv\Dotenv;

$root = dirname(path: __DIR__);

// OpenTelemetry decides on Fiber support while the autoloader loads, reading
// OTEL_PHP_FIBERS_ENABLED from $_SERVER only — and the built-in server never
// copies the environment there. Without it, every traced call inside an MCP
// tool (mcp/sdk runs handlers in Fibers) warns, which the web runtime throws.
// It has to come from the process environment (compose), not from .env: the
// .env file is read only after the autoloader.
if (!isset($_SERVER['OTEL_PHP_FIBERS_ENABLED']) && getenv('OTEL_PHP_FIBERS_ENABLED') !== false) {
    $_SERVER['OTEL_PHP_FIBERS_ENABLED'] = getenv('OTEL_PHP_FIBERS_ENABLED');
}

require_once $root . '/vendor/autoload.php';

// Compose passes the configuration as environment variables; a .env file is
// optional and never overrides one that is already set.
Dotenv::createImmutable(paths: $root)->safeLoad();
Environment::prepare();
