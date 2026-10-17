<?php

declare(strict_types=1);

namespace App\Application\Payments\GetGatewayProtection;

/**
 * The live state of what protects the payment gateway — the circuit breaker
 * and the bulkhead — read without calling the gateway or moving the breaker.
 */
interface GatewayProtection
{
    /**
     * @return array{name: string, breaker: array<string, mixed>, bulkhead: array{maxConcurrent: int, inUse: int}, now: string}
     */
    public function snapshot(): array;
}
