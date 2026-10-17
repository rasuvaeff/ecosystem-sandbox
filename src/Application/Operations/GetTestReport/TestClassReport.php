<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * The tests of one test class, filed under the layer its namespace names.
 */
final readonly class TestClassReport implements \JsonSerializable
{
    /**
     * @param 'Domain'|'Application'|'Infrastructure'|'Architecture'|'Other' $layer
     * @param list<TestCaseReport> $cases
     */
    public function __construct(
        public string $name,
        public string $layer,
        public array $cases,
    ) {}

    /**
     * @return array{name: string, layer: string, cases: list<TestCaseReport>}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'layer' => $this->layer, 'cases' => $this->cases];
    }
}
