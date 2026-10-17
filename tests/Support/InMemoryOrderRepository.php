<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderRepository;

/**
 * The repository contract in memory: the handlers under test see real
 * aggregates, and a test reads back what they stored.
 */
final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    /** @var list<string> */
    public array $saved = [];

    public function __construct(Order ...$orders)
    {
        foreach ($orders as $order) {
            $this->orders[$order->id()->value] = $order;
        }
    }

    public function find(OrderId $id): ?Order
    {
        return $this->orders[$id->value] ?? null;
    }

    public function findForUpdate(OrderId $id): ?Order
    {
        return $this->find(id: $id);
    }

    public function addIfAbsent(Order $order): bool
    {
        if (isset($this->orders[$order->id()->value])) {
            return false;
        }
        $this->orders[$order->id()->value] = $order;

        return true;
    }

    public function save(Order $order): void
    {
        $this->orders[$order->id()->value] = $order;
        $this->saved[] = $order->id()->value;
    }
}
