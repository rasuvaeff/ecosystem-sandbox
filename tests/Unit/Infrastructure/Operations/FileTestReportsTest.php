<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Operations;

use App\Infrastructure\Operations\TestReports\FileTestReports;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The tests page reads what the tools wrote: a JUnit report, Infection's log,
 * the ratchet's trend and the gate's floor. A missing or broken file is a run
 * that has not happened.
 */
#[Test]
#[Covers(FileTestReports::class)]
final class FileTestReportsTest
{
    private string $root;

    #[BeforeTest]
    public function makeRoot(): void
    {
        $this->root = sys_get_temp_dir() . '/file-test-reports-' . bin2hex(random_bytes(length: 6));
        mkdir($this->root . '/build/reports', recursive: true);
    }

    #[AfterTest]
    public function removeRoot(): void
    {
        foreach (['build/reports/unit.xml', 'build/reports/mutation-trend.tsv', 'build/infection-log.json', 'infection.json5'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root . '/build/reports');
        @rmdir($this->root . '/build');
        @rmdir($this->root);
    }

    public function nothingWrittenIsNoRun(): void
    {
        $reports = new FileTestReports(buildDirectory: $this->root . '/build');

        Assert::null($reports->suite(name: 'Unit'));
        Assert::null($reports->mutation());
    }

    public function aBrokenReportIsNoRun(): void
    {
        file_put_contents($this->root . '/build/reports/unit.xml', data: '<testsuites><testsuite');
        file_put_contents($this->root . '/build/infection-log.json', data: '{"stats":');

        $reports = new FileTestReports(buildDirectory: $this->root . '/build');

        Assert::null($reports->suite(name: 'Unit'));
        Assert::null($reports->mutation());
    }

    /** Layers come from the namespace, statuses from the children, distributions from the property output. */
    public function aJunitReportBecomesLayersCasesAndDistributions(): void
    {
        file_put_contents($this->root . '/build/reports/unit.xml', data: <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites name="Testo">
              <testsuite name="Unit">
                <testsuite name="App\Tests\Unit\Domain\Ordering\OrderTest">
                  <testcase name="aPaidOrderStaysPaid" time="0.25"/>
                  <testcase name="anAmountExistsInsideItsBounds" time="0.5">
                    <system-err><![CDATA[Property "anAmountExistsInsideItsBounds" distribution: outside 52% (156/300), inside 48% (144/300)]]></system-err>
                  </testcase>
                </testsuite>
                <testsuite name="App\Tests\Unit\Architecture\ArchitectureTest">
                  <testcase name="nothingReadsTheSystemClock" time="0.25"><failure message="no"/></testcase>
                  <testcase name="skippedOne" time="0"><skipped/></testcase>
                </testsuite>
                <testsuite name="App\Tests\Unit\PlatformContractsTest">
                  <testcase name="contract" time="0"><error message="boom"/></testcase>
                </testsuite>
              </testsuite>
            </testsuites>
            XML);

        $suite = new FileTestReports(buildDirectory: $this->root . '/build')->suite(name: 'Unit');

        Assert::same($suite?->name, expected: 'Unit');
        Assert::same($suite?->tests, expected: 5);
        Assert::same($suite?->failures, expected: 2);
        Assert::same($suite?->seconds, expected: 1.0);
        Assert::same(array_map(static fn ($class): array => [$class->name, $class->layer], $suite->classes ?? []), [
            ['OrderTest', 'Domain'],
            ['ArchitectureTest', 'Architecture'],
            ['PlatformContractsTest', 'Other'],
        ]);
        $order = $suite->classes[0]->cases ?? [];
        Assert::same($order[0]->distribution, []);
        Assert::same($order[1]->distribution, [
            ['label' => 'outside', 'hits' => 156, 'runs' => 300],
            ['label' => 'inside', 'hits' => 144, 'runs' => 300],
        ]);
        Assert::same(array_map(static fn ($case): string => $case->status, $suite->classes[1]->cases ?? []), ['failed', 'skipped']);
        Assert::same($suite->classes[2]->cases[0]->status ?? null, expected: 'failed');
    }

    /** Counts per file, one example per file with the tests that failed on it, the trend and the floor. */
    public function infectionsLogBecomesTheMutationReport(): void
    {
        $mutant = static fn (string $file, int $line, string $output): array => [
            'mutator' => ['mutatorName' => 'MethodCallRemoval', 'originalSourceCode' => '', 'mutatedSourceCode' => '', 'originalFilePath' => '/workspace/sandbox/src/Domain/' . $file, 'originalStartLine' => $line],
            'diff' => "@@ @@\n     {\n-        \$this->move();\n+\n     }\n",
            'processOutput' => $output,
        ];
        $caught = "Testo\n" . json_encode(['failures' => [
            ['test' => 'App\Tests\Unit\Domain\Ordering\OrderTest::aPaidOrderStaysPaid:0:1'],
            ['test' => 'App\Tests\Unit\Domain\Ordering\OrderTest::aPaidOrderStaysPaid:1:0'],
        ]]);
        file_put_contents($this->root . '/build/infection-log.json', json_encode([
            'stats' => ['totalMutantsCount' => 4, 'killedCount' => 2, 'killedByStaticAnalysisCount' => 0, 'escapedCount' => 1, 'notCoveredCount' => 1, 'timeOutCount' => 0, 'msi' => 50],
            'killed' => [$mutant('Order.php', 10, $caught), $mutant('Order.php', 20, $caught)],
            'escaped' => [$mutant('Amount.php', 5, '')],
            'uncovered' => [$mutant('Order.php', 30, '')],
        ]));
        file_put_contents($this->root . '/build/reports/mutation-trend.tsv', data: "run-1\t97.5\nnot a line\nrun-2\t100\n");
        file_put_contents($this->root . '/infection.json5', data: "{\n    // floor\n    \"minMsi\": 95,\n}\n");

        $mutation = new FileTestReports(buildDirectory: $this->root . '/build')->mutation();

        Assert::same([$mutation?->total, $mutation?->caught, $mutation?->missed, $mutation?->msi, $mutation?->minMsi], [4, 2, 2, 50.0, 95.0]);
        Assert::same($mutation->files ?? [], [
            ['file' => 'src/Domain/Order.php', 'caught' => 2, 'missed' => 1],
            ['file' => 'src/Domain/Amount.php', 'caught' => 0, 'missed' => 1],
        ]);
        Assert::same(count($mutation->examples ?? []), expected: 1);
        Assert::same($mutation->examples[0]->line ?? null, expected: 10);
        Assert::same($mutation->examples[0]->caughtBy ?? [], ['OrderTest::aPaidOrderStaysPaid']);
        Assert::same($mutation->examples[0]->diff ?? '', expected: "     {\n-        \$this->move();\n+\n     }");
        Assert::same($mutation->trend ?? [], [['run' => 'run-1', 'msi' => 97.5], ['run' => 'run-2', 'msi' => 100.0]]);
    }
}
