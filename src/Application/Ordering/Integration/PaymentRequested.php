<?php

declare(strict_types=1);

namespace App\Application\Ordering\Integration;

use App\Application\Shared\Integration\IntegrationEvent;
use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * An order wants its money taken. Recorded with the transition to
 * `processing`; the payment worker consumes it.
 *
 * The correlation id and the trace context carry the checkout request's
 * identity across the process boundary. Both are optional on the way in: a
 * message without them is still paid, under an id of its own.
 */
final readonly class PaymentRequested implements IntegrationEvent
{
    /**
     * @param non-empty-string $orderId
     * @param non-empty-string $gatewayIdempotencyKey stable per order: the gateway charges once whatever the retries
     * @param array<string, mixed> $trace W3C trace context plus the tracer's own scalar keys
     */
    public function __construct(
        public string $orderId,
        public int $amountCents,
        public string $gatewayIdempotencyKey,
        public ?string $correlationId = null,
        public array $trace = [],
    ) {}

    public static function type(): string
    {
        return 'payment.requested';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function schema(): SchemaInterface
    {
        return Schema::object([
            'orderId' => Schema::string()->min(length: 1)->max(OrderId::MAX_LENGTH),
            'amountCents' => Schema::int()->min(Amount::MIN_CENTS)->max(Amount::MAX_CENTS),
            'gatewayIdempotencyKey' => Schema::string()->min(length: 1)->max(length: 128),
            'correlationId' => Schema::nullable(Schema::string()->max(length: 128))->optional(),
            'trace' => Schema::mapOf(Schema::json())->optional(),
        ])->strip()->into(self::class);
    }

    public function aggregateId(): string
    {
        return $this->orderId;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId,
            'amountCents' => $this->amountCents,
            'gatewayIdempotencyKey' => $this->gatewayIdempotencyKey,
            'correlationId' => $this->correlationId,
            'trace' => $this->trace,
        ];
    }
}
