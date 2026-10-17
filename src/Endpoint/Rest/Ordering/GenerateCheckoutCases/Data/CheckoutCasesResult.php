<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\GenerateCheckoutCases\Data;

/**
 * Checkout requests generated from the contract; nothing is sent.
 */
final readonly class CheckoutCasesResult implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $cases
     */
    public function __construct(
        public int $seed,
        public string $operation,
        public array $cases,
    ) {}

    /**
     * @return array{seed: int, operation: string, cases: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return ['seed' => $this->seed, 'operation' => $this->operation, 'cases' => $this->cases];
    }
}
