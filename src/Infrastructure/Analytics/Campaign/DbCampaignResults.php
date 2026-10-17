<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\Campaign;

use App\Application\Analytics\GetAnalyticsReport\CampaignResults;
use App\Domain\Analytics\OrderSource;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Shared\TableNames;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Shop-page orders by the campaign that brought them, from Analytics' own
 * projection (order_summary): the channel was resolved when the touch was
 * credited, the status follows the order's events. It works in plain
 * `make ui`, without ClickHouse. Only shop-page orders are counted, the same
 * line the ClickHouse report draws, so generated headers never become a
 * campaign.
 */
final readonly class DbCampaignResults implements CampaignResults
{
    public function __construct(private ConnectionInterface $db) {}

    public function rows(): array
    {
        // Aggregates are expressions of yiisoft/db's query builder; every
        // value in them is a bound parameter.
        $paid = [':paid' => OrderStatus::Paid->value];
        /** @var list<array{channel: string, source: ?string, medium: ?string, campaign: ?string, orders: int|string, paid_orders: int|string, paid_amount: int|string}> $groups */
        $groups = new Query(db: $this->db)
            ->select(columns: [
                'channel' => 'utm_channel',
                'source' => 'utm_source',
                'medium' => 'utm_medium',
                'campaign' => 'utm_campaign',
                'orders' => new Expression(expression: 'COUNT(*)'),
                'paid_orders' => new Expression(expression: 'COUNT(CASE WHEN status = :paid THEN 1 END)', params: $paid),
                'paid_amount' => new Expression(expression: 'COALESCE(SUM(CASE WHEN status = :paid THEN amount_cents END), 0)', params: $paid),
            ])
            ->from(tables: TableNames::ORDER_SUMMARY)
            ->where(condition: ['source' => OrderSource::Shop->value])
            ->andWhere(condition: ['not', ['utm_channel' => null]])
            ->groupBy(columns: ['utm_channel', 'utm_source', 'utm_medium', 'utm_campaign'])
            ->all();

        $rows = array_map(
            callback: static fn (array $row): array => [
                'channel' => $row['channel'],
                'source' => $row['source'],
                'medium' => $row['medium'],
                'campaign' => $row['campaign'],
                'orders' => (int) $row['orders'],
                'paidOrders' => (int) $row['paid_orders'],
                'paidAmountCents' => (int) $row['paid_amount'],
            ],
            array: $groups,
        );
        usort($rows, static fn (array $a, array $b): int => [$b['orders'], $a['channel']] <=> [$a['orders'], $b['channel']]);

        return $rows;
    }
}
