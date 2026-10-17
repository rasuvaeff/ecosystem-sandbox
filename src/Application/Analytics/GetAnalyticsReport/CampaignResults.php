<?php

declare(strict_types=1);

namespace App\Application\Analytics\GetAnalyticsReport;

interface CampaignResults
{
    /**
     * Shop-page orders by the campaign that brought them.
     *
     * @return list<array{channel: string, source: ?string, medium: ?string, campaign: ?string, orders: int, paidOrders: int, paidAmountCents: int}>
     */
    public function rows(): array;
}
