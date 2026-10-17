<?php

declare(strict_types=1);

namespace App\Application\Operations\GetTestReport;

/**
 * What the last local or CI runs of the test tools left behind: the testo
 * suites' reports and Infection's log. Read, never produced, by the
 * application: the tools are development dependencies.
 */
interface TestReports
{
    /**
     * @param non-empty-string $name the suite, as testo.php names it
     */
    public function suite(string $name): ?SuiteReport;

    public function mutation(): ?MutationReport;
}
