<?php

declare(strict_types=1);

/**
 * The CI job summary: the same reports the tests page reads, as Markdown on
 * stdout (`>> "$GITHUB_STEP_SUMMARY"`). Run inside the app container after
 * make testo / property-test / mutation.
 */

use App\Application\Operations\GetTestReport\GetTestReportHandler;
use App\Application\Operations\GetTestReport\SuiteReport;
use Psr\Container\ContainerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var ContainerInterface $container */
$container = require __DIR__ . '/support/container.php';
$report = $container->get(GetTestReportHandler::class)->handle();

$suite = static function (string $title, ?SuiteReport $suite): void {
    if ($suite === null) {
        return;
    }
    $mark = $suite->failures === 0 ? 'passed' : "**{$suite->failures} failed**";
    echo "### {$title}: {$suite->tests} tests, {$mark}, " . sprintf('%.1f', $suite->seconds) . " s\n\n";
    echo "| Layer | Classes | Tests | Failed |\n|---|---:|---:|---:|\n";
    $layers = array_fill_keys(['Domain', 'Application', 'Infrastructure', 'Architecture', 'Other'], ['classes' => 0, 'tests' => 0, 'failed' => 0]);
    foreach ($suite->classes as $class) {
        $layers[$class->layer] ??= ['classes' => 0, 'tests' => 0, 'failed' => 0];
        ++$layers[$class->layer]['classes'];
        foreach ($class->cases as $case) {
            ++$layers[$class->layer]['tests'];
            $layers[$class->layer]['failed'] += $case->status === 'failed' ? 1 : 0;
        }
    }
    foreach (array_filter($layers, static fn (array $row): bool => $row['classes'] > 0) as $layer => $row) {
        echo "| {$layer} | {$row['classes']} | {$row['tests']} | {$row['failed']} |\n";
    }
    $properties = [];
    foreach ($suite->classes as $class) {
        foreach ($class->cases as $case) {
            if ($case->distribution !== []) {
                $parts = array_map(static fn (array $part): string => sprintf('%s %d/%d', $part['label'], $part['hits'], $part['runs']), $case->distribution);
                $properties[] = "| {$class->name}::{$case->name} | " . implode(', ', $parts) . ' |';
            }
        }
    }
    if ($properties !== []) {
        echo "\n<details><summary>Property tests: how the generated cases fell</summary>\n\n| Property | Distribution |\n|---|---|\n" . implode("\n", $properties) . "\n\n</details>\n";
    }
    echo "\n";
};

echo "## Test report\n\n";
$suite('Unit', $report->unit);
$suite('Property', $report->property);

$mutation = $report->mutation;
if ($mutation !== null) {
    echo "### Mutation: {$mutation->caught} of {$mutation->total} planted bugs caught, MSI {$mutation->msi}%" . ($mutation->minMsi === null ? '' : " (floor {$mutation->minMsi}%)") . "\n\n";
    if ($mutation->missed > 0) {
        echo "**{$mutation->missed} escaped.**\n\n";
    }
    echo "| File | Caught | Missed |\n|---|---:|---:|\n";
    foreach (array_slice($mutation->files, 0, 15) as $file) {
        echo "| `{$file['file']}` | {$file['caught']} | {$file['missed']} |\n";
    }
    if (count($mutation->trend) > 1) {
        echo "\nMSI by run: " . implode(' → ', array_map(static fn (array $point): string => (string) $point['msi'], array_slice($mutation->trend, -10))) . "\n";
    }
    echo "\n";
}

if ($report->unit === null && $report->property === null && $mutation === null) {
    echo "No reports under build/: run make testo, make property-test or make mutation first.\n";
}
