<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Access;

enum Role: string
{
    case SupportAgent = 'support-agent';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SupportAgent => [Permission::OrdersView, Permission::PaymentsMonitor],
        };
    }
}
