<?php

declare(strict_types=1);

namespace App\Application\Payments\GetGatewayStatus;

use App\Domain\Payments\GatewayHealth;

final readonly class GetGatewayStatusHandler
{
    public function __construct(private GatewayHealth $gateway) {}

    /**
     * @return 'available'|'unavailable'
     */
    public function handle(): string
    {
        return $this->gateway->isAvailable() ? 'available' : 'unavailable';
    }
}
