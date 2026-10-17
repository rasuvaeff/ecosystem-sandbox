<?php

declare(strict_types=1);

namespace App\Application\Payments\GetReceipt;

final readonly class GetReceiptHandler
{
    public function __construct(private ReceiptFinder $receipts) {}

    public function handle(GetReceiptQuery $query): ?ReceiptView
    {
        return $this->receipts->forOrder(orderId: $query->orderId);
    }
}
