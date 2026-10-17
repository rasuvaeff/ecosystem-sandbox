<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics\ClickHouse;

use App\Application\Ordering\Integration\OrderResultEvent;
use App\Application\Ordering\OrderingApi;
use App\Domain\Analytics\OrderSource;
use App\Domain\Ordering\Exception\OrderNotFoundException;
use App\Domain\Ordering\OrderId;
use App\Domain\Shared\TableNames;
use App\Infrastructure\Shared\Outbox\IntegrationMessages;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

/**
 * Writes each payment result into ClickHouse for the analytics page. The
 * table is a ReplacingMergeTree keyed by the outbox message id, so a message
 * redelivered after another sink failed collapses into one row.
 *
 * The table is created by the ClickHouse migration
 * (`clickhouse:migrations:migrate`, yii3-clickhouse-toolkit), never here.
 *
 * This shows clickhouse-toolkit's writer inside the result fan-out;
 * yii3-outbox-clickhouse's exporter reads the outbox on its own and would race
 * the fan-out's Processor for the same messages.
 */
final readonly class ExportOrderResultToClickHouse implements PublisherInterface
{
    public const string TABLE = TableNames::CLICKHOUSE_ORDER_RESULTS;

    public function __construct(
        private IntegrationMessages $messages,
        private ClickHouseClientFactory $clientFactory,
        private OrderingApi $ordering,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $result = $this->messages->parse(message: $message, expected: OrderResultEvent::class);

        try {
            $order = $this->ordering->order(orderId: new OrderId(value: $result->orderId()));
        } catch (OrderNotFoundException) {
            // The same rule as the payment worker: no silent $0 row for an
            // order the database does not know.
            throw PublishException::terminal(message: 'Order of the result event does not exist', outboxMessage: $message);
        }

        try {
            $client = $this->clientFactory->create();
            new ClickHouseBatchWriter(
                client: $client,
                table: self::TABLE,
                columns: ['event_id', 'order_id', 'status', 'amount_cents', 'occurred_at', 'source'],
            )->write([[
                'event_id' => $message->getId(),
                'order_id' => $result->orderId(),
                'status' => $result->status(),
                'amount_cents' => $order->amount->cents,
                'occurred_at' => $message->getCreatedAt()->format(format: 'Y-m-d H:i:s.v'),
                'source' => OrderSource::ofCustomer(customerId: $order->customerId->value)->value,
            ]]);
        } catch (\Throwable $exception) {
            throw new PublishException(message: 'ClickHouse is unavailable', outboxMessage: $message, previous: $exception);
        }
    }
}
