<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * One test. A property test that classifies its inputs carries how its
 * generated cases fell: how many of them reached each branch it guards.
 */
final readonly class TestCaseReport implements \JsonSerializable
{
    /**
     * @param 'passed'|'failed'|'skipped' $status
     * @param list<array{label: string, hits: int, runs: int}> $distribution
     */
    public function __construct(
        public string $name,
        public string $status,
        public array $distribution,
    ) {}

    /**
     * @return array{name: string, status: string, distribution: list<array{label: string, hits: int, runs: int}>}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'status' => $this->status, 'distribution' => $this->distribution];
    }
}
