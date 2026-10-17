<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Ordering\GetOrder\OrderView;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\Policy\OrderState;
use App\Domain\Ordering\Specification\OrderSpecifications;
use App\Infrastructure\Ordering\Persistence\DbOrderRepository;
use App\Infrastructure\Ordering\ReadModel\DbOrderListReader;
use Psr\Container\ContainerInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * One policy, two interpreters: for any set of orders in PostgreSQL, the SQL
 * the read model builds from a policy selects exactly the orders the
 * aggregate's in-memory check accepts.
 */
#[Test]
#[Covers(DbOrderListReader::class)]
final class OrderPoliciesSqlTest
{
    /**
     * @param list<OrderStatus> $statuses one order per entry
     */
    #[Property(runs: 15)]
    public function sqlAndMemoryAgreeOnEveryState(array $statuses): void
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, levels: 2) . '/bin/support/container.php';
        $db = $container->get(ConnectionInterface::class);
        \assert($db instanceof ConnectionInterface);
        $repository = new DbOrderRepository(db: $db, clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-03-01T10:00:00+00:00')));
        // A customer of its own per run, so only this run's orders are read.
        $customer = new CustomerId(value: 'policy-' . bin2hex(random_bytes(length: 6)));
        $orders = [];
        foreach ($statuses as $index => $status) {
            $order = Order::restore(id: new OrderId(value: $customer->value . '-' . $index), customerId: $customer, amount: new Amount(cents: 100 + $index), status: $status);
            $repository->addIfAbsent(order: $order);
            $orders[] = $order;
        }
        $reader = new DbOrderListReader(db: $db);

        foreach (OrderState::cases() as $state) {
            $fromSql = array_map(
                callback: static fn (OrderView $view): string => $view->orderId->value,
                array: $reader->matching(specification: OrderSpecifications::listing(status: null, minAmountCents: null, customerId: $customer->value, sort: 'amount', limit: 100, state: $state)),
            );
            $inMemory = [];
            foreach ($orders as $order) {
                if ($order->satisfies(policy: $state->policy())) {
                    $inMemory[] = $order->id()->value;
                }
            }
            sort($fromSql);
            sort($inMemory);

            Assert::same($fromSql, $inMemory);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function sqlAndMemoryAgreeOnEveryStateGenerators(): array
    {
        return ['statuses' => Gen::arrayOf(Gen::enum(OrderStatus::class), maxSize: 8)];
    }
}
