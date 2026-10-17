<?php

declare(strict_types=1);

namespace App\Endpoint\Mcp\Assistant;

use App\Application\Ordering\GetOrder\OrderView;
use App\Application\Ordering\OrderingApi;
use App\Application\Payments\GetGatewayStatus\GetGatewayStatusHandler;
use App\Domain\Assistant\Access\Permission;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\TransitionRecord;
use Mcp\Capability\Attribute\McpTool;
use Rasuvaeff\Yii3McpRbacBridge\RequiredPermission;

/**
 * The shop as an AI agent sees it over MCP: read-only, the same query
 * handlers the HTTP endpoints use — no tool answers from a hardcoded value,
 * and none can change an order. Each tool names the permission it needs.
 */
final readonly class ShopTools
{
    public function __construct(
        private OrderingApi $ordering,
        private GetGatewayStatusHandler $gateway,
    ) {}

    /** @return array{orderId: string, status: string, found: bool} */
    #[McpTool(name: 'order.status')]
    #[RequiredPermission(Permission::OrdersView->value)]
    public function status(string $orderId): array
    {
        $id = OrderId::tryFrom(value: $orderId);

        try {
            $order = $id instanceof OrderId ? $this->ordering->order(orderId: $id) : null;
        } catch (OrderNotFoundException) {
            $order = null;
        }

        return ['orderId' => $orderId, 'status' => $order?->status->value ?? 'unknown', 'found' => $order instanceof OrderView];
    }

    /** @return array{orderId: string, found: bool, events: list<array{transition: string, from: string, to: string, at: string}>} */
    #[McpTool(name: 'order.timeline')]
    #[RequiredPermission(Permission::OrdersView->value)]
    public function timeline(string $orderId): array
    {
        $id = OrderId::tryFrom(value: $orderId);

        try {
            $records = $id instanceof OrderId ? $this->ordering->timeline(orderId: $id) : null;
        } catch (OrderNotFoundException) {
            $records = null;
        }

        return [
            'orderId' => $orderId,
            'found' => $records !== null,
            'events' => array_map(
                callback: static fn (TransitionRecord $record): array => ['transition' => $record->transition, 'from' => $record->from, 'to' => $record->to, 'at' => $record->at->format(format: DATE_RFC3339)],
                array: $records ?? [],
            ),
        ];
    }

    #[McpTool(name: 'payment.gateway_status')]
    #[RequiredPermission(Permission::PaymentsMonitor->value)]
    public function gatewayStatus(): string
    {
        return $this->gateway->handle();
    }
}
