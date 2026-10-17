<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Persistence;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderRepository;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\Specification\OrderSpecifications;
use App\Domain\Shared\TableNames;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Specification\QueryApplier;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class DbOrderRepository implements OrderRepository
{
    public const string TABLE = TableNames::ORDERS;

    /** `timestamp with time zone`, to the second (the column has no fraction). */
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
    ) {}

    public function find(OrderId $id): ?Order
    {
        return $this->one(id: $id, forUpdate: false);
    }

    public function findForUpdate(OrderId $id): ?Order
    {
        return $this->one(id: $id, forUpdate: true);
    }

    public function addIfAbsent(Order $order): bool
    {
        $inserted = $this->db->createCommand()->upsert(
            table: self::TABLE,
            insertColumns: [
                'id' => $order->id()->value,
                'customer_id' => $order->customerId()->value,
                'amount_cents' => $order->amount()->cents,
                'status' => $order->status()->value,
                'created_at' => $now = $this->clock->now()->format(format: self::TIMESTAMP),
                'updated_at' => $now,
            ],
            updateColumns: false,
        )->execute();

        return $inserted > 0;
    }

    public function save(Order $order): void
    {
        $this->db->createCommand()->update(
            table: self::TABLE,
            columns: ['status' => $order->status()->value, 'updated_at' => $this->clock->now()->format(format: self::TIMESTAMP)],
            condition: ['id' => $order->id()->value],
        )->execute();
    }

    private function one(OrderId $id, bool $forUpdate): ?Order
    {
        $query = new Query(db: $this->db)->from(tables: self::TABLE);
        QueryApplier::apply(specification: OrderSpecifications::byId(id: $id), query: $query);
        if ($forUpdate) {
            $query->for(value: 'UPDATE');
        }
        /** @var array{id: string, customer_id: string, amount_cents: int|string, status: string}|null $row */
        $row = $query->one();

        return $row === null ? null : Order::restore(
            id: new OrderId(value: $row['id']),
            customerId: new CustomerId(value: $row['customer_id']),
            amount: new Amount(cents: (int) $row['amount_cents']),
            status: OrderStatus::from(value: $row['status']),
        );
    }
}
