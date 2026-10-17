<?php

declare(strict_types=1);

namespace App\Domain\Shared\Specification;

use Rasuvaeff\Specification\ComparisonSpecification;
use Rasuvaeff\Specification\CompositeSpecification;
use Rasuvaeff\Specification\LimitSpecification;
use Rasuvaeff\Specification\NotSpecification;
use Rasuvaeff\Specification\OffsetSpecification;
use Rasuvaeff\Specification\OrConditionSpecification;
use Rasuvaeff\Specification\OrderBySpecification;
use Rasuvaeff\Specification\OrSpecification;
use Rasuvaeff\Specification\RawSpecification;
use Rasuvaeff\Specification\Specification;
use Rasuvaeff\Specification\SpecificationVisitor;

/**
 * The second interpreter of a rasuvaeff/specification tree: where
 * QueryBuildingVisitor turns a rule into SQL, this one checks it against one
 * object's column values. A domain policy is then written once and holds
 * both in the aggregate and in a read-model query.
 *
 * Only what can be decided from values is supported: comparisons, AND, OR
 * and NOT. Ordering, limit and offset do not filter and are ignored; raw SQL
 * and raw OR conditions cannot be evaluated here and are refused, never
 * guessed.
 *
 * @implements SpecificationVisitor<bool>
 */
final readonly class InMemorySpecification implements SpecificationVisitor
{
    /**
     * @param array<string, scalar|null> $row column => value
     */
    private function __construct(private array $row) {}

    /**
     * @param array<string, scalar|null> $row
     */
    public static function satisfies(Specification $specification, array $row): bool
    {
        return (bool) $specification->accept(new self(row: $row));
    }

    public function visitComparison(ComparisonSpecification $specification): bool
    {
        $column = $specification->getColumn();
        if (!array_key_exists(key: $column, array: $this->row)) {
            throw new \LogicException(message: 'The specification names a column the object does not have: ' . $column);
        }
        $actual = $this->row[$column];
        $expected = $specification->getValue();
        if ($expected instanceof \DateTimeInterface) {
            throw new \LogicException(message: 'A date comparison is not evaluated in memory');
        }

        return match ($specification->getOperator()) {
            '=', 'is' => $actual === $expected,
            '!=', '<>', 'is not' => $actual !== $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'in' => in_array(needle: $actual, haystack: (array) $expected, strict: true),
            'not in' => !in_array(needle: $actual, haystack: (array) $expected, strict: true),
            'between' => $actual >= ((array) $expected)[0] && $actual <= ((array) $expected)[1],
            'not between' => $actual < ((array) $expected)[0] || $actual > ((array) $expected)[1],
            default => throw new \LogicException(message: 'Operator "' . $specification->getOperator() . '" is not evaluated in memory'),
        };
    }

    public function visitComposite(CompositeSpecification $specification): bool
    {
        foreach ($specification->getSpecifications() as $part) {
            if (!$part->accept($this)) {
                return false;
            }
        }

        return true;
    }

    public function visitNot(NotSpecification $specification): bool
    {
        return !$specification->getSpecification()->accept($this);
    }

    public function visitOr(OrSpecification $specification): bool
    {
        foreach ($specification->getSpecifications() as $part) {
            if ($part->accept($this)) {
                return true;
            }
        }

        return false;
    }

    public function visitOrCondition(OrConditionSpecification $specification): bool
    {
        throw new \LogicException(message: 'A raw OR condition is not evaluated in memory');
    }

    public function visitRaw(RawSpecification $specification): bool
    {
        throw new \LogicException(message: 'Raw SQL is not evaluated in memory');
    }

    public function visitOrderBy(OrderBySpecification $specification): bool
    {
        return true;
    }

    public function visitLimit(LimitSpecification $specification): bool
    {
        return true;
    }

    public function visitOffset(OffsetSpecification $specification): bool
    {
        return true;
    }
}
