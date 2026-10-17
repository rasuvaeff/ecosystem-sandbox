<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * Everything the tests page shows; a part is null until its tool has run.
 */
final readonly class TestReport implements \JsonSerializable
{
    public function __construct(
        public ?SuiteReport $unit,
        public ?SuiteReport $property,
        public ?MutationReport $mutation,
    ) {}

    /**
     * @return array{unit: SuiteReport|null, property: SuiteReport|null, mutation: MutationReport|null}
     */
    public function jsonSerialize(): array
    {
        return ['unit' => $this->unit, 'property' => $this->property, 'mutation' => $this->mutation];
    }
}
