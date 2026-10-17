<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Marketing\ShowExperiment\Data;

/**
 * The pay-button variant for this visitor and the signed exposure receipt.
 */
final readonly class ExposureResult implements \JsonSerializable
{
    public function __construct(
        public string $experiment,
        public string $variant,
        public string $reason,
        public bool $sticky,
        public string $receipt,
    ) {}

    /**
     * @return array{experiment: string, variant: string, reason: string, sticky: bool, receipt: string}
     */
    public function jsonSerialize(): array
    {
        return ['experiment' => $this->experiment, 'variant' => $this->variant, 'reason' => $this->reason, 'sticky' => $this->sticky, 'receipt' => $this->receipt];
    }
}
