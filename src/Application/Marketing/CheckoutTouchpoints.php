<?php

declare(strict_types=1);

namespace App\Application\Marketing;

use Rasuvaeff\Yii3AbTesting\AssignmentReceipt;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;

/**
 * What the shop page told the checkout about marketing — the campaign touch
 * and the A/B exposure receipt — kept for the length of one request. The
 * checkout itself (Ordering) never sees it: Marketing reads it when the
 * order is placed.
 */
final class CheckoutTouchpoints
{
    private ?UtmTouchpoint $touchpoint = null;

    private ?AssignmentReceipt $receipt = null;

    public function remember(?UtmTouchpoint $touchpoint, ?AssignmentReceipt $receipt): void
    {
        $this->touchpoint = $touchpoint;
        $this->receipt = $receipt;
    }

    public function touchpoint(): ?UtmTouchpoint
    {
        return $this->touchpoint;
    }

    public function receipt(): ?AssignmentReceipt
    {
        return $this->receipt;
    }
}
