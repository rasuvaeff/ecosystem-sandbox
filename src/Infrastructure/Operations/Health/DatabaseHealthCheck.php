<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Health;

use Rasuvaeff\Yii3HealthCheck\HealthCheck;
use Rasuvaeff\Yii3HealthCheck\HealthResult;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private ConnectionInterface $db) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthResult
    {
        // The driver's message names the host, port and user; /health is
        // public and read by the operator page, so only the class travels.
        try {
            $this->db->createCommand('SELECT 1')->queryScalar();
        } catch (\Throwable $exception) {
            return HealthResult::fail(
                name: $this->name(),
                message: 'Database is unreachable',
                data: ['error' => $exception::class],
            );
        }

        return HealthResult::pass(name: $this->name());
    }
}
