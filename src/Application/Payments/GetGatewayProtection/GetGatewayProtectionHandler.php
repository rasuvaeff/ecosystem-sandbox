<?php

declare(strict_types=1);

namespace App\Application\Payments\GetGatewayProtection;

final readonly class GetGatewayProtectionHandler
{
    public function __construct(private GatewayProtection $protection) {}

    /**
     * @return array{name: string, breaker: array<string, mixed>, bulkhead: array{maxConcurrent: int, inUse: int}, now: string}
     */
    public function handle(): array
    {
        return $this->protection->snapshot();
    }
}
