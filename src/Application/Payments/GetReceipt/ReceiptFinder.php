<?php

declare(strict_types=1);

namespace App\Application\Payments\GetReceipt;

use App\Domain\Ordering\OrderId;

interface ReceiptFinder
{
    /**
     * The receipt of a paid order with a download URL that expires: the
     * token, not the file id, is what grants the download.
     */
    public function forOrder(OrderId $orderId): ?ReceiptView;
}
