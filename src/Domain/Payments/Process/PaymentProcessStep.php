<?php

declare(strict_types=1);

namespace App\Domain\Payments\Process;

/**
 * The payment's process, step by step. Each step starts from exactly one
 * status, so a redelivered or late event finds the process elsewhere and is
 * refused as a value, never applied twice.
 */
enum PaymentProcessStep: string
{
    case Capture = 'capture';
    case Decline = 'decline';
    case Abandon = 'abandon';
    case ConfirmNotCharged = 'confirm_not_charged';
    case ConfirmCharged = 'confirm_charged';

    public function source(): PaymentProcessStatus
    {
        return match ($this) {
            self::Capture, self::Decline, self::Abandon => PaymentProcessStatus::Requested,
            self::ConfirmNotCharged, self::ConfirmCharged => PaymentProcessStatus::Abandoned,
        };
    }

    public function target(): PaymentProcessStatus
    {
        return match ($this) {
            self::Capture => PaymentProcessStatus::Captured,
            self::Decline => PaymentProcessStatus::Declined,
            self::Abandon => PaymentProcessStatus::Abandoned,
            self::ConfirmNotCharged => PaymentProcessStatus::NotCharged,
            self::ConfirmCharged => PaymentProcessStatus::Recovered,
        };
    }
}
