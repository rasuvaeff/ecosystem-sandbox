<?php

declare(strict_types=1);

namespace App\Application\Payments\GetPaymentProcess;

use App\Domain\Payments\Process\PaymentProcess;

/**
 * The payment saga's state, for the evidence page.
 */
final readonly class PaymentProcessView implements \JsonSerializable
{
    public function __construct(
        public string $status,
    ) {}

    public static function of(PaymentProcess $process): self
    {
        return new self(status: $process->status()->value);
    }

    /**
     * @return array{status: string}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status];
    }
}
