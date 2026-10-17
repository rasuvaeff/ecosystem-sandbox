<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * The order's lifecycle, defined once: yii3-workflow's state machine is built
 * from these cases (config/common/params.php), and the aggregate checks the
 * same from/to pairs when it records a transition.
 */
enum OrderTransition: string
{
    case StartPayment = 'start_payment';
    case MarkPaid = 'mark_paid';
    case MarkFailed = 'mark_failed';

    /**
     * The payment was given up unanswered, and the reconciliation found that
     * the bank had taken the money: the failed order is paid after all.
     */
    case RecoverPayment = 'recover_payment';

    public function source(): OrderStatus
    {
        return match ($this) {
            self::StartPayment => OrderStatus::Pending,
            self::MarkPaid, self::MarkFailed => OrderStatus::Processing,
            self::RecoverPayment => OrderStatus::PaymentFailed,
        };
    }

    public function target(): OrderStatus
    {
        return match ($this) {
            self::StartPayment => OrderStatus::Processing,
            self::MarkPaid, self::RecoverPayment => OrderStatus::Paid,
            self::MarkFailed => OrderStatus::PaymentFailed,
        };
    }

    /**
     * @return list<array{name: string, from: OrderStatus, to: OrderStatus}>
     */
    public static function definitions(): array
    {
        return array_map(
            callback: static fn (self $transition): array => ['name' => $transition->value, 'from' => $transition->source(), 'to' => $transition->target()],
            array: self::cases(),
        );
    }
}
