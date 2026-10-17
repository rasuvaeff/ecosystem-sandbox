<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderTransition;
use App\Infrastructure\Ordering\Persistence\DbOrderRepository;
use Psr\Container\ContainerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * The repository against the real PostgreSQL: the clock it is given stamps
 * the row, at insert and on every save. The table's own default once stood
 * in, and updated_at then stayed the insert time for good.
 */
#[Test]
#[Covers(DbOrderRepository::class)]
final class DbOrderRepositoryTest
{
    public function theClockStampsTheInsertAndEverySave(): void
    {
        $db = $this->db();
        $id = new OrderId(value: 'testo-clock-' . bin2hex(random_bytes(length: 4)));
        $order = Order::place(id: $id, customerId: new CustomerId(value: 'testo'), amount: new Amount(cents: 1999));

        Assert::true(new DbOrderRepository(db: $db, clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-03-01T10:00:00+00:00')))->addIfAbsent(order: $order));
        Assert::same($this->stamps(db: $db, id: $id), ['created_at' => '2026-03-01 10:00:00+00', 'updated_at' => '2026-03-01 10:00:00+00']);

        Assert::true($order->apply(transition: OrderTransition::StartPayment)->isOk());
        new DbOrderRepository(db: $db, clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-03-01T10:05:30+00:00')))->save(order: $order);

        Assert::same($this->stamps(db: $db, id: $id), ['created_at' => '2026-03-01 10:00:00+00', 'updated_at' => '2026-03-01 10:05:30+00']);
    }

    /**
     * @return array{created_at: string, updated_at: string}
     */
    private function stamps(ConnectionInterface $db, OrderId $id): array
    {
        /** @var array{created_at: string, updated_at: string} $row */
        $row = new Query(db: $db)
            ->select(columns: ['created_at', 'updated_at'])
            ->from(tables: DbOrderRepository::TABLE)
            ->where(condition: ['id' => $id->value])
            ->one();

        return $row;
    }

    private function db(): ConnectionInterface
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, levels: 2) . '/bin/support/container.php';
        $db = $container->get(ConnectionInterface::class);
        \assert($db instanceof ConnectionInterface);

        return $db;
    }
}
