<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\TestReports;

use App\Application\Operations\GetTestReport\MutantExample;
use App\Application\Operations\GetTestReport\MutationReport;
use App\Application\Operations\GetTestReport\SuiteReport;
use App\Application\Operations\GetTestReport\TestCaseReport;
use App\Application\Operations\GetTestReport\TestClassReport;
use App\Application\Operations\GetTestReport\TestReports;
use Rasuvaeff\Schema\Schema;

/**
 * Reads what the tools wrote under build/: testo's JUnit reports
 * (`make testo`, `make property-test`), Infection's JSON log and the
 * ratchet's MSI trend (`make mutation`). A file that is missing or that does
 * not parse is a run that has not happened; it is never an error page.
 */
final readonly class FileTestReports implements TestReports
{
    /** Planted bugs shown with the test that caught them: one per file and per kind, at most this many. */
    private const int EXAMPLES = 6;

    /** Diff lines kept per example: enough to see the change, short enough to read. */
    private const int DIFF_LINES = 12;

    public function __construct(
        /** the build/ directory the make targets write into */
        private string $buildDirectory,
    ) {}

    public function suite(string $name): ?SuiteReport
    {
        $file = $this->buildDirectory . '/reports/' . strtolower($name) . '.xml';
        $xml = $this->xml(file: $file);
        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        $classes = [];
        $tests = 0;
        $failures = 0;
        $seconds = 0.0;
        $classNodes = $xml->xpath(expression: '//testsuite[testcase]');
        foreach (is_array($classNodes) ? $classNodes : [] as $class) {
            $cases = [];
            foreach ($class->testcase as $case) {
                $status = $case->failure->count() + $case->error->count() > 0 ? 'failed' : ($case->skipped->count() > 0 ? 'skipped' : 'passed');
                $cases[] = new TestCaseReport(
                    name: (string) $case['name'],
                    status: $status,
                    distribution: $this->distribution(output: (string) $case->{'system-err'}),
                );
                ++$tests;
                $failures += $status === 'failed' ? 1 : 0;
                $seconds += (float) $case['time'];
            }
            $className = (string) $class['name'];
            $classes[] = new TestClassReport(name: $this->shortName(class: $className), layer: $this->layer(class: $className), cases: $cases);
        }

        return new SuiteReport(name: $name, ranAt: $this->modifiedAt(file: $file), tests: $tests, failures: $failures, seconds: $seconds, classes: $classes);
    }

    public function mutation(): ?MutationReport
    {
        $file = $this->buildDirectory . '/infection-log.json';
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            return null;
        }

        $mutant = Schema::object([
            'mutator' => Schema::object([
                'mutatorName' => Schema::string(),
                'originalFilePath' => Schema::string(),
                'originalStartLine' => Schema::int(),
            ])->strip(),
            'diff' => Schema::string(),
            'processOutput' => Schema::string(),
        ])->strip();
        $parsed = Schema::object([
            'stats' => Schema::object([
                'totalMutantsCount' => Schema::int()->min(value: 0),
                'killedCount' => Schema::int()->min(value: 0),
                'killedByStaticAnalysisCount' => Schema::int()->min(value: 0),
                'escapedCount' => Schema::int()->min(value: 0),
                'notCoveredCount' => Schema::int()->min(value: 0),
                'timeOutCount' => Schema::int()->min(value: 0),
                'msi' => Schema::float()->allowInt(),
            ])->strip(),
            'killed' => Schema::arrayOf($mutant),
            'escaped' => Schema::arrayOf($mutant),
            'uncovered' => Schema::arrayOf($mutant),
        ])->strip()->parse(input: json_decode(json: $contents, associative: true));
        if (!$parsed->ok) {
            return null;
        }
        /** @var array{stats: array{totalMutantsCount: int<0, max>, killedCount: int<0, max>, killedByStaticAnalysisCount: int<0, max>, escapedCount: int<0, max>, notCoveredCount: int<0, max>, timeOutCount: int<0, max>, msi: float|int}, killed: list<array{mutator: array{mutatorName: string, originalFilePath: string, originalStartLine: int}, diff: string, processOutput: string}>, escaped: list<array{mutator: array{mutatorName: string, originalFilePath: string, originalStartLine: int}, diff: string, processOutput: string}>, uncovered: list<array{mutator: array{mutatorName: string, originalFilePath: string, originalStartLine: int}, diff: string, processOutput: string}>} $log */
        $log = $parsed->value;

        // Per source file: planted bugs the tests caught and the ones they missed.
        /** @var array<string, int> $caught */
        $caught = [];
        /** @var array<string, int> $missed */
        $missed = [];
        foreach ($log['killed'] as $entry) {
            $path = $this->sourcePath(path: $entry['mutator']['originalFilePath']);
            $caught[$path] = ($caught[$path] ?? 0) + 1;
        }
        foreach ([...$log['escaped'], ...$log['uncovered']] as $entry) {
            $path = $this->sourcePath(path: $entry['mutator']['originalFilePath']);
            $missed[$path] = ($missed[$path] ?? 0) + 1;
        }
        // Most mutated first; sorting is stable, so the path breaks a tie.
        $totals = [];
        foreach (array_keys($caught + $missed) as $path) {
            $totals[$path] = ($caught[$path] ?? 0) + ($missed[$path] ?? 0);
        }
        ksort($totals);
        arsort($totals);
        $files = [];
        foreach (array_keys($totals) as $path) {
            $files[] = ['file' => $path, 'caught' => $caught[$path] ?? 0, 'missed' => $missed[$path] ?? 0];
        }

        $examples = [];
        $seen = [];
        foreach ($log['killed'] as $entry) {
            $path = $this->sourcePath(path: $entry['mutator']['originalFilePath']);
            $caughtBy = $this->caughtBy(output: $entry['processOutput']);
            // One per file, per kind of change and per line changed: six
            // different bugs, not one bug six times.
            $mutator = $entry['mutator']['mutatorName'];
            $changed = preg_match(pattern: '/^-\s*(.+)$/m', subject: $entry['diff'], matches: $line) === 1 ? $line[1] : '';
            if (count($examples) === self::EXAMPLES || isset($examples[$path]) || isset($seen[$mutator]) || isset($seen[$changed]) || $caughtBy === []) {
                continue;
            }
            $seen[$mutator] = true;
            $seen[$changed] = true;
            $examples[$path] = new MutantExample(
                file: $path,
                line: $entry['mutator']['originalStartLine'],
                mutator: $entry['mutator']['mutatorName'],
                diff: $this->shortDiff(diff: $entry['diff']),
                caughtBy: $caughtBy,
            );
        }

        $stats = $log['stats'];

        return new MutationReport(
            ranAt: $this->modifiedAt(file: $file),
            total: $stats['totalMutantsCount'],
            caught: $stats['killedCount'] + $stats['killedByStaticAnalysisCount'] + $stats['timeOutCount'],
            missed: $stats['escapedCount'] + $stats['notCoveredCount'],
            msi: (float) $stats['msi'],
            minMsi: $this->minMsi(),
            files: $files,
            examples: array_values($examples),
            trend: $this->trend(),
        );
    }

    /**
     * The gate's floor, from infection.json5 next to build/: JSON5, so only
     * this one key is read, not the whole file.
     */
    private function minMsi(): ?float
    {
        $file = dirname($this->buildDirectory) . '/infection.json5';
        $contents = is_file($file) ? file_get_contents($file) : false;

        return $contents !== false && preg_match(pattern: '/"minMsi"\s*:\s*(\d+(?:\.\d+)?)/', subject: $contents, matches: $match) === 1 ? (float) $match[1] : null;
    }

    private function xml(string $file): ?\SimpleXMLElement
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            return null;
        }
        $previous = libxml_use_internal_errors(use_errors: true);
        $xml = simplexml_load_string(data: $contents, options: LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(use_errors: $previous);

        return $xml === false ? null : $xml;
    }

    /**
     * Property testing prints how the generated cases fell:
     * `Property "x" distribution: inside 48% (144/300), outside 52% (156/300)`.
     *
     * @return list<array{label: string, hits: int, runs: int}>
     */
    private function distribution(string $output): array
    {
        $at = strpos($output, needle: 'distribution: ');
        if ($at === false) {
            return [];
        }
        preg_match_all(pattern: '/\s*([^,(]+?) \d+% \((\d+)\/(\d+)\)/', subject: substr($output, offset: $at + strlen(string: 'distribution: ')), matches: $found, flags: PREG_SET_ORDER);
        $distribution = [];
        foreach ($found as $match) {
            $distribution[] = ['label' => $match[1], 'hits' => (int) $match[2], 'runs' => (int) $match[3]];
        }

        return $distribution;
    }

    /**
     * The tests that failed on a planted bug, from the testo run Infection
     * recorded for it (`--json`): `Class::method` without the data-set suffix.
     *
     * @return list<string>
     */
    private function caughtBy(string $output): array
    {
        $start = strpos($output, needle: '{');
        if ($start === false) {
            return [];
        }
        $parsed = Schema::object([
            'failures' => Schema::arrayOf(Schema::object(['test' => Schema::string()])->strip()),
        ])->strip()->parse(input: json_decode(json: substr($output, offset: $start), associative: true));
        if (!$parsed->ok) {
            return [];
        }
        /** @var array{failures: list<array{test: string}>} $run */
        $run = $parsed->value;
        $tests = [];
        foreach ($run['failures'] as $failure) {
            $name = preg_replace(pattern: '/(:\d+)+$/', replacement: '', subject: $failure['test']) ?? $failure['test'];
            $tests[$name] = $this->shortName(class: $name);
        }

        return array_values(array_slice($tests, offset: 0, length: 3));
    }

    /**
     * The ratchet's ledger as `make mutation` exported it: one `run<TAB>msi`
     * line per recorded run.
     *
     * @return list<array{run: string, msi: float}>
     */
    private function trend(): array
    {
        $file = $this->buildDirectory . '/reports/mutation-trend.tsv';
        $lines = is_file($file) ? file(filename: $file, flags: FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        $trend = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $fields = explode(separator: "\t", string: $line);
            if (count($fields) === 2 && is_numeric($fields[1])) {
                $trend[] = ['run' => $fields[0], 'msi' => (float) $fields[1]];
            }
        }

        return $trend;
    }

    private function shortDiff(string $diff): string
    {
        $lines = array_values(array_filter(
            array: explode(separator: "\n", string: $diff),
            callback: static fn (string $line): bool => $line !== '@@ @@' && trim($line) !== '',
        ));

        return implode(separator: "\n", array: array_slice($lines, offset: 0, length: self::DIFF_LINES));
    }

    /** `App\Tests\Unit\Domain\Ordering\OrderTest::x` → `OrderTest::x`. */
    private function shortName(string $class): string
    {
        $at = strrpos($class, needle: '\\');

        return $at === false ? $class : substr($class, offset: $at + 1);
    }

    /**
     * @return 'Domain'|'Application'|'Infrastructure'|'Architecture'|'Other'
     */
    private function layer(string $class): string
    {
        foreach (['Domain', 'Application', 'Infrastructure', 'Architecture'] as $layer) {
            if (str_contains($class, needle: '\\' . $layer . '\\')) {
                return $layer;
            }
        }

        return 'Other';
    }

    /** An absolute path inside the container, as the page shows it: from src/ on. */
    private function sourcePath(string $path): string
    {
        $at = strpos($path, needle: '/src/');

        return $at === false ? basename($path) : substr($path, offset: $at + 1);
    }

    /** When the tool wrote the file: the time of the run, read from the file, not from the clock. */
    private function modifiedAt(string $file): \DateTimeImmutable
    {
        $modified = filemtime($file);

        return new \DateTimeImmutable(datetime: '@' . ($modified === false ? '0' : (string) $modified));
    }
}
