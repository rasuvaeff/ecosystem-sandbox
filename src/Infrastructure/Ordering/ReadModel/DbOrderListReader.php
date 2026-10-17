<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\ReadModel;

use App\Application\Ordering\GetOrder\OrderView;
use App\Application\Ordering\ListOrders\OrderListReader;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Ordering\Persistence\DbOrderRepository;
use Rasuvaeff\Specification\QueryApplier;
use Rasuvaeff\Specification\Specification;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class DbOrderListReader implements OrderListReader
{
    public function __construct(private ConnectionInterface $db) {}

    public function matching(Specification $specification): array
    {
        $query = new Query(db: $this->db)
            ->select(columns: ['id', 'customer_id', 'amount_cents', 'status'])
            ->from(tables: DbOrderRepository::TABLE);
        QueryApplier::apply(specification: $specification, query: $query);

        $orders = [];
        /** @var array{id: string, customer_id: string, amount_cents: int|string, status: string} $row */
        foreach ($query->all() as $row) {
            $orders[] = new OrderView(
                orderId: new OrderId(value: $row['id']),
                customerId: new CustomerId(value: $row['customer_id']),
                amount: new Amount(cents: (int) $row['amount_cents']),
                status: OrderStatus::from(value: $row['status']),
            );
        }

        return $orders;
    }
}
