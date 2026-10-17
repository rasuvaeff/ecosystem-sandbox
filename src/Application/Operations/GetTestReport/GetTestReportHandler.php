<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * The last runs of the unit and property suites and of the mutation gate,
 * for the page that explains how the shop is tested.
 */
final readonly class GetTestReportHandler
{
    public function __construct(private TestReports $reports) {}

    public function handle(): TestReport
    {
        return new TestReport(
            unit: $this->reports->suite(name: 'Unit'),
            property: $this->reports->suite(name: 'Property'),
            mutation: $this->reports->mutation(),
        );
    }
}
