<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Analytics\OrderSource;
use App\Domain\Marketing\Event\CheckoutAttributed;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\Event\OrderPlaced;
use App\Domain\Ordering\Event\OrderStatusChanged;
use App\Domain\Ordering\OrderId;
use App\Domain\Ordering\OrderTransition;
use App\Domain\Shared\TableNames;
use App\Infrastructure\Analytics\Campaign\DbCampaignResults;
use App\Infrastructure\Analytics\Projection\OrderSummaryProjector;
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
 * The projection on PostgreSQL: for any orders, with any campaigns and any
 * lifecycles, the campaign report built from order_summary is the one a
 * plain count over the same events gives.
 */
#[Test]
#[Covers(OrderSummaryProjector::class)]
#[Covers(DbCampaignResults::class)]
final class OrderSummaryProjectionTest
{
    /**
     * @param list<array{bool, ?string, list<OrderTransition>}> $orders shop order?, campaign (null: no touch), transitions taken
     */
    #[Property(runs: 10)]
    public function theReportCountsWhatTheEventsSay(array $orders): void
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, levels: 2) . '/bin/support/container.php';
        $db = $container->get(ConnectionInterface::class);
        \assert($db instanceof ConnectionInterface);
        $db->createCommand()->delete(table: TableNames::ORDER_SUMMARY)->execute();
        $projector = new OrderSummaryProjector(db: $db, clock: new StaticClock(now: new \DateTimeImmutable(datetime: '2026-03-01T10:00:00+00:00')));

        $expected = [];
        foreach ($orders as $index => [$fromShop, $campaign, $transitions]) {
            $id = new OrderId(value: 'summary-' . $index);
            $amount = new Amount(cents: 100 * ($index + 1));
            $customer = new CustomerId(value: $fromShop ? OrderSource::SHOP_CUSTOMER_ID : 'scripted');
            $projector->onPlaced(event: new OrderPlaced(orderId: $id, customerId: $customer, amount: $amount));
            if ($campaign !== null) {
                $projector->onAttributed(event: new CheckoutAttributed(orderId: $id, channel: 'email', source: 'newsletter', medium: 'email', campaign: $campaign));
            }
            $paid = false;
            foreach ($transitions as $transition) {
                $projector->onStatusChanged(event: new OrderStatusChanged(orderId: $id, amount: $amount, transition: $transition, from: $transition->source(), to: $transition->target()));
                $paid = $transition === OrderTransition::MarkPaid;
            }
            if ($fromShop && $campaign !== null) {
                $expected[$campaign] ??= ['orders' => 0, 'paidOrders' => 0, 'paidAmountCents' => 0];
                ++$expected[$campaign]['orders'];
                $expected[$campaign]['paidOrders'] += $paid ? 1 : 0;
                $expected[$campaign]['paidAmountCents'] += $paid ? $amount->cents : 0;
            }
        }

        $report = [];
        foreach (new DbCampaignResults(db: $db)->rows() as $row) {
            Assert::same([$row['channel'], $row['source'], $row['medium']], ['email', 'newsletter', 'email']);
            $report[(string) $row['campaign']] = ['orders' => $row['orders'], 'paidOrders' => $row['paidOrders'], 'paidAmountCents' => $row['paidAmountCents']];
        }
        ksort($report);
        ksort($expected);

        Assert::same($report, $expected);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theReportCountsWhatTheEventsSayGenerators(): array
    {
        // A lifecycle is a path through the transition table, never an
        // arbitrary list: the projection follows real orders.
        $lifecycle = Gen::elements([
            [],
            [OrderTransition::StartPayment],
            [OrderTransition::StartPayment, OrderTransition::MarkPaid],
            [OrderTransition::StartPayment, OrderTransition::MarkFailed],
        ]);

        return ['orders' => Gen::arrayOf(Gen::tuple(Gen::bool(), Gen::nullable(Gen::elements(['autumn', 'spring'])), $lifecycle), maxSize: 8)];
    }
}
