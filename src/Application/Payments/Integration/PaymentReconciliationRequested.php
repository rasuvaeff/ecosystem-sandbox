<?php

declare(strict_types=1);

namespace App\Application\Payments\Integration;

use App\Application\Shared\Integration\IntegrationEvent;
use App\Domain\Ordering\OrderId;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * The payment of an order was given up without an answer from the bank:
 * whether the money was taken is unknown. Recorded with the process's step
 * to `abandoned`; the payment worker reconciles it.
 */
final readonly class PaymentReconciliationRequested implements IntegrationEvent
{
    /**
     * @param non-empty-string $orderId
     */
    public function __construct(public string $orderId) {}

    public static function type(): string
    {
        return 'payment.reconciliation_requested';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function schema(): SchemaInterface
    {
        return Schema::object(['orderId' => Schema::string()->min(length: 1)->max(OrderId::MAX_LENGTH)])->strip()->into(self::class);
    }

    public function aggregateId(): string
    {
        return $this->orderId;
    }

    public function toPayload(): array
    {
        return ['orderId' => $this->orderId];
    }
}
