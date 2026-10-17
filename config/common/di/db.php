<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;

return [
    CacheInterface::class => static fn (): CacheInterface => new FileCache(
        dirname(__DIR__, 3) . '/runtime/cache',
    ),
    ConnectionInterface::class => static function (SchemaCache $schemaCache): ConnectionInterface {
        $driver = new Driver(
            dsn: sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                App\Environment::string(name: 'DB_HOST', default: 'postgres'),
                App\Environment::string(name: 'DB_PORT', default: '5432'),
                App\Environment::string(name: 'DB_NAME', default: 'sandbox'),
            ),
            username: App\Environment::string(name: 'DB_USER', default: 'sandbox'),
            password: App\Environment::string(name: 'DB_PASSWORD', default: 'sandbox'),
        );

        return new Connection(driver: $driver, schemaCache: $schemaCache);
    },
];
