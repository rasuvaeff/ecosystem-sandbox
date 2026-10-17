<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * Where an order came from, as the reports split it: the shop page buys as
 * one customer; everything else — the contract page's generated requests
 * (any amount up to a million dollars), the check scripts — is "other" and
 * never swamps the shop's totals.
 */
enum OrderSource: string
{
    case Shop = 'shop';
    case Other = 'other';
    public const string SHOP_CUSTOMER_ID = 'ui-visitor';

    public static function ofCustomer(string $customerId): self
    {
        return $customerId === self::SHOP_CUSTOMER_ID ? self::Shop : self::Other;
    }
}
