<?php

declare(strict_types=1);

namespace App\Domain\Payments\Process;

enum PaymentProcessStatus: string
{
    /** The worker is taking the money. */
    case Requested = 'requested';

    /** The bank approved the charge. */
    case Captured = 'captured';

    /** The bank declined, or refused the request: no money was taken. */
    case Declined = 'declined';

    /** No answer on the last attempt: the outcome is unknown until reconciled. */
    case Abandoned = 'abandoned';

    /** Reconciled: the bank holds no charge for this payment. */
    case NotCharged = 'not_charged';

    /** Reconciled: the bank had charged; the failed order was paid after all. */
    case Recovered = 'recovered';
}
