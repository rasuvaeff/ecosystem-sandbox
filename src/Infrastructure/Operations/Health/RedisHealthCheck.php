<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Health;

use Predis\Client;
use Rasuvaeff\Yii3HealthCheck\HealthCheck;
use Rasuvaeff\Yii3HealthCheck\HealthResult;

final readonly class RedisHealthCheck implements HealthCheck
{
    public function __construct(private Client $redis) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthResult
    {
        return $this->redis->executeRaw(arguments: ['PING']) === 'PONG'
            ? HealthResult::pass(name: $this->name())
            : HealthResult::fail(name: $this->name(), message: 'Redis did not return PONG');
    }
}
