<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Access;

/**
 * What an AI agent may ask the shop. Each MCP tool names the one it needs
 * (#[RequiredPermission]); yiisoft/rbac decides.
 */
enum Permission: string
{
    case OrdersView = 'orders.view';
    case PaymentsMonitor = 'payments.monitor';
}
