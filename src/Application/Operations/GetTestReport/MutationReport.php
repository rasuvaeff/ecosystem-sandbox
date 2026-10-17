<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * Infection's last run over the domain and the use cases: how many deliberate
 * bugs were planted, how many the tests caught, where, and a few of them
 * shown with the test that caught each. The trend is the ratchet's ledger.
 */
final readonly class MutationReport implements \JsonSerializable
{
    /**
     * @param list<array{file: string, caught: int, missed: int}> $files
     * @param list<MutantExample> $examples
     * @param list<array{run: string, msi: float}> $trend
     */
    public function __construct(
        public \DateTimeImmutable $ranAt,
        public int $total,
        public int $caught,
        public int $missed,
        public float $msi,
        /** the floor the gate holds the run to (infection.json5 minMsi) */
        public ?float $minMsi,
        public array $files,
        public array $examples,
        public array $trend,
    ) {}

    /**
     * @return array{ranAt: string, total: int, caught: int, missed: int, msi: float, minMsi: float|null, files: list<array{file: string, caught: int, missed: int}>, examples: list<MutantExample>, trend: list<array{run: string, msi: float}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'ranAt' => $this->ranAt->format(format: DATE_RFC3339),
            'total' => $this->total,
            'caught' => $this->caught,
            'missed' => $this->missed,
            'msi' => $this->msi,
            'minMsi' => $this->minMsi,
            'files' => $this->files,
            'examples' => $this->examples,
            'trend' => $this->trend,
        ];
    }
}
