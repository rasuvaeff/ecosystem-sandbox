<?php

declare(strict_types=1);

namespace App\Domain\Payments;

interface GatewayHealth
{
    public function isAvailable(): bool;
}
