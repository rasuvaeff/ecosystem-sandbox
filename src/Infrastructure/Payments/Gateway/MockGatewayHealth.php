<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateway;

use App\Domain\Payments\GatewayHealth;
use App\Infrastructure\Payments\Health\PaymentMockHealthCheck;
use Rasuvaeff\Yii3HealthCheck\HealthStatus;

/**
 * The gateway's health as the readiness probe sees it: one check, two readers.
 */
final readonly class MockGatewayHealth implements GatewayHealth
{
    public function __construct(private PaymentMockHealthCheck $check) {}

    public function isAvailable(): bool
    {
        return $this->check->check()->status === HealthStatus::Pass;
    }
}
