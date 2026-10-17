<?php

declare(strict_types=1);

namespace App\Application\Ordering\Integration;

use App\Domain\Ordering\OrderId;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * The payment was declined or given up; the order is failed.
 */
final readonly class OrderPaymentFailed implements OrderResultEvent
{
    /**
     * @param non-empty-string $orderId
     */
    public function __construct(public string $orderId) {}

    public static function type(): string
    {
        return 'order.payment_failed';
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

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function status(): string
    {
        return 'payment_failed';
    }
}
