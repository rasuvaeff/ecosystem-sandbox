<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Operations;

use App\Application\Operations\GetTestReport\GetTestReportHandler;
use App\Application\Operations\GetTestReport\MutantExample;
use App\Application\Operations\GetTestReport\MutationReport;
use App\Application\Operations\GetTestReport\SuiteReport;
use App\Application\Operations\GetTestReport\TestCaseReport;
use App\Application\Operations\GetTestReport\TestClassReport;
use App\Application\Operations\GetTestReport\TestReport;
use App\Application\Operations\GetTestReport\TestReports;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * The tests page's answer: the suites asked for by their testo names, and
 * every report serialized field by field, as the page and the document read it.
 */
#[Test]
#[Covers(GetTestReportHandler::class)]
#[Covers(TestReport::class)]
#[Covers(SuiteReport::class)]
#[Covers(TestClassReport::class)]
#[Covers(TestCaseReport::class)]
#[Covers(MutationReport::class)]
#[Covers(MutantExample::class)]
final class GetTestReportHandlerTest
{
    public function theSuitesAndTheGateAreReportedAsThePageReadsThem(): void
    {
        $reports = Understudy::for(TestReports::class);
        when(static fn () => $reports->suite('Unit'))->returns(new SuiteReport(
            name: 'Unit',
            ranAt: new \DateTimeImmutable(datetime: '2026-10-17T08:00:00+00:00'),
            tests: 2,
            failures: 1,
            seconds: 0.12345,
            classes: [new TestClassReport(name: 'OrderTest', layer: 'Domain', cases: [
                new TestCaseReport(name: 'aPaidOrderStaysPaid', status: 'failed', distribution: [['label' => 'inside', 'hits' => 3, 'runs' => 10]]),
            ])],
        ));
        when(static fn () => $reports->suite('Property'))->returns(null);
        when(static fn () => $reports->mutation())->returns(new MutationReport(
            ranAt: new \DateTimeImmutable(datetime: '2026-10-17T09:30:00+00:00'),
            total: 10,
            caught: 9,
            missed: 1,
            msi: 90.0,
            minMsi: 95.0,
            files: [['file' => 'src/Domain/Order.php', 'caught' => 9, 'missed' => 1]],
            examples: [new MutantExample(file: 'src/Domain/Order.php', line: 7, mutator: 'LogicalNot', diff: '-a', caughtBy: ['OrderTest::x'])],
            trend: [['run' => 'r1', 'msi' => 90.0]],
        ));

        $json = json_encode(new GetTestReportHandler(reports: $reports)->handle(), flags: JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        Assert::same(json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR), [
            'unit' => [
                'name' => 'Unit',
                'ranAt' => '2026-10-17T08:00:00+00:00',
                'tests' => 2,
                'failures' => 1,
                'seconds' => 0.123,
                'classes' => [[
                    'name' => 'OrderTest',
                    'layer' => 'Domain',
                    'cases' => [['name' => 'aPaidOrderStaysPaid', 'status' => 'failed', 'distribution' => [['label' => 'inside', 'hits' => 3, 'runs' => 10]]]],
                ]],
            ],
            'property' => null,
            'mutation' => [
                'ranAt' => '2026-10-17T09:30:00+00:00',
                'total' => 10,
                'caught' => 9,
                'missed' => 1,
                'msi' => 90.0,
                'minMsi' => 95.0,
                'files' => [['file' => 'src/Domain/Order.php', 'caught' => 9, 'missed' => 1]],
                'examples' => [['file' => 'src/Domain/Order.php', 'line' => 7, 'mutator' => 'LogicalNot', 'diff' => '-a', 'caughtBy' => ['OrderTest::x']]],
                'trend' => [['run' => 'r1', 'msi' => 90.0]],
            ],
        ]);
    }
}
