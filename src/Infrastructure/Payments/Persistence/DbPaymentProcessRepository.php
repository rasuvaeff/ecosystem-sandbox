<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Persistence;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Process\PaymentProcess;
use App\Domain\Payments\Process\PaymentProcessRepository;
use App\Domain\Payments\Process\PaymentProcessStatus;
use App\Domain\Shared\TableNames;
use Psr\Clock\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class DbPaymentProcessRepository implements PaymentProcessRepository
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
    ) {}

    public function find(OrderId $orderId): ?PaymentProcess
    {
        /** @var array{order_id: non-empty-string, amount_cents: int|string, gateway_key: non-empty-string, status: string}|null $row */
        $row = new Query(db: $this->db)
            ->select(columns: ['order_id', 'amount_cents', 'gateway_key', 'status'])
            ->from(tables: TableNames::PAYMENT_PROCESSES)
            ->where(condition: ['order_id' => $orderId->value])
            ->one();

        return $row === null ? null : PaymentProcess::restore(
            orderId: new OrderId(value: $row['order_id']),
            amount: new Amount(cents: (int) $row['amount_cents']),
            gatewayKey: $row['gateway_key'],
            status: PaymentProcessStatus::from(value: $row['status']),
        );
    }

    public function addIfAbsent(PaymentProcess $process): bool
    {
        $now = $this->clock->now()->format(format: self::TIMESTAMP);

        return $this->db->createCommand()->upsert(
            table: TableNames::PAYMENT_PROCESSES,
            insertColumns: [
                'order_id' => $process->orderId()->value,
                'amount_cents' => $process->amount()->cents,
                'gateway_key' => $process->gatewayKey(),
                'status' => $process->status()->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            updateColumns: false,
        )->execute() > 0;
    }

    public function save(PaymentProcess $process): void
    {
        $this->db->createCommand()->update(
            table: TableNames::PAYMENT_PROCESSES,
            columns: [
                'status' => $process->status()->value,
                'updated_at' => $this->clock->now()->format(format: self::TIMESTAMP),
            ],
            condition: ['order_id' => $process->orderId()->value],
        )->execute();
    }
}
