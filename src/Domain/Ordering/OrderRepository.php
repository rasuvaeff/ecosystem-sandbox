<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

interface OrderRepository
{
    public function find(OrderId $id): ?Order;

    /**
     * Reads the order with a row lock held until the transaction ends.
     */
    public function findForUpdate(OrderId $id): ?Order;

    /**
     * @return bool false when an order with this id already existed
     */
    public function addIfAbsent(Order $order): bool;

    public function save(Order $order): void;
}
