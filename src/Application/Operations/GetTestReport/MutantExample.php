<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * One planted bug: the change Infection made and the tests that failed on it.
 */
final readonly class MutantExample implements \JsonSerializable
{
    /**
     * @param list<string> $caughtBy
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $mutator,
        public string $diff,
        public array $caughtBy,
    ) {}

    /**
     * @return array{file: string, line: int, mutator: string, diff: string, caughtBy: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'mutator' => $this->mutator, 'diff' => $this->diff, 'caughtBy' => $this->caughtBy];
    }
}
