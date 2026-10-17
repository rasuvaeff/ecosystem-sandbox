<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * One testo suite's last run.
 */
final readonly class SuiteReport implements \JsonSerializable
{
    /**
     * @param list<TestClassReport> $classes
     */
    public function __construct(
        public string $name,
        public \DateTimeImmutable $ranAt,
        public int $tests,
        public int $failures,
        public float $seconds,
        public array $classes,
    ) {}

    /**
     * @return array{name: string, ranAt: string, tests: int, failures: int, seconds: float, classes: list<TestClassReport>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'ranAt' => $this->ranAt->format(format: DATE_RFC3339),
            'tests' => $this->tests,
            'failures' => $this->failures,
            'seconds' => round(num: $this->seconds, precision: 3),
            'classes' => $this->classes,
        ];
    }
}
