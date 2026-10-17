<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\Shared;

use App\Application\Marketing\GetAttribution\GetAttributionHandler;
use App\Application\Marketing\GetAttribution\GetAttributionQuery;
use App\Application\Ordering\GetOrderEvidence\GetOrderEvidenceHandler;
use App\Application\Ordering\GetOrderEvidence\GetOrderEvidenceQuery;
use App\Application\Payments\GetPaymentProcess\GetPaymentProcessHandler;
use App\Application\Payments\GetPaymentProcess\GetPaymentProcessQuery;
use App\Application\Payments\GetReceipt\GetReceiptHandler;
use App\Application\Payments\GetReceipt\GetReceiptQuery;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderId;
use App\Endpoint\Rest\Ordering\ShowEvidence\Data\OrderEvidenceResult;

/**
 * One order's evidence, composed from the contexts that hold a part of it:
 * Ordering's own, Marketing's attribution, Payments' receipt and saga. The
 * evidence endpoint and the order screen share it, so the two cannot drift.
 */
final readonly class OrderEvidenceComposer
{
    public function __construct(
        private GetOrderEvidenceHandler $evidence,
        private GetAttributionHandler $attribution,
        private GetReceiptHandler $receipt,
        private GetPaymentProcessHandler $payment,
    ) {}

    /**
     * @throws OrderNotFoundException
     */
    public function compose(OrderId $orderId): OrderEvidenceResult
    {
        return OrderEvidenceResult::compose(
            evidence: $this->evidence->handle(query: new GetOrderEvidenceQuery(orderId: $orderId)),
            attribution: $this->attribution->handle(query: new GetAttributionQuery(orderId: $orderId)),
            receipt: $this->receipt->handle(query: new GetReceiptQuery(orderId: $orderId)),
            payment: $this->payment->handle(query: new GetPaymentProcessQuery(orderId: $orderId)),
        );
    }
}
