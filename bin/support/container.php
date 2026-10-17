<?php

declare(strict_types=1);

/**
 * Real application container for standalone smoke scripts: the same config
 * groups the console runner uses, so a smoke that resolves a service proves
 * the DI wiring of this sandbox and not a hand-built graph.
 *
 * @return ContainerInterface
 */

use Psr\Container\ContainerInterface;
use Yiisoft\Config\Config;
use Yiisoft\Config\ConfigPaths;
use Yiisoft\Config\Modifier\RecursiveMerge;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

$root = dirname(__DIR__, 2);
require_once $root . '/src/autoload.php';

$config = new Config(
    new ConfigPaths($root, 'config', 'vendor'),
    null,
    [RecursiveMerge::groups('params', 'params-console')],
    'params-console',
);

return new Container(
    ContainerConfig::create()
        ->withValidate(false)
        ->withDefinitions($config->get('di-console'))
        ->withProviders($config->get('di-providers-console')),
);
