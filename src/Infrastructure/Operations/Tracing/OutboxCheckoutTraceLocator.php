<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Tracing;

use App\Application\Operations\GetCheckoutTrace\CheckoutTraceLocator;
use App\Application\Ordering\Integration\PaymentRequested;
use App\Domain\Ordering\OrderId;
use App\Domain\Shared\TableNames;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Unknown;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * The checkout's trace id is the W3C traceparent it wrote into its
 * `payment.requested` message.
 */
final readonly class OutboxCheckoutTraceLocator implements CheckoutTraceLocator
{
    private const string TRACEPARENT = '/\A00-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}\z/';

    public function __construct(private ConnectionInterface $db) {}

    public function traceIdOf(OrderId $orderId): null|string|false
    {
        $payload = new Query(db: $this->db)
            ->select(columns: ['payload'])
            ->from(tables: TableNames::OUTBOX)
            ->where(condition: ['aggregate_id' => $orderId->value, 'type' => PaymentRequested::type()])
            ->scalar();
        if (!is_string($payload)) {
            return false;
        }
        $parsed = Schema::object(['trace' => Schema::object(['traceparent' => Schema::string()], unknown: Unknown::Strip)], unknown: Unknown::Strip)
            ->parseJson($payload);

        return $parsed->ok && preg_match(pattern: self::TRACEPARENT, subject: $parsed->orThrow()['trace']['traceparent'], matches: $match) === 1 ? $match[1] : null;
    }
}
