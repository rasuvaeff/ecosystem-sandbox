<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Operations\ShowTenant\Data;

/**
 * The tenant TenantResolutionMiddleware resolved from X-Tenant-Id.
 */
final readonly class TenantResult implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
    ) {}

    /**
     * @return array{id: string, name: string, status: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'status' => $this->status];
    }
}
