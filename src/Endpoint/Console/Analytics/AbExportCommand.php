<?php

declare(strict_types=1);

namespace App\Endpoint\Console\Analytics;

use App\Application\Analytics\ExportAbEvents\AnalyticsSchema;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One batch of A/B events from the outbox into ClickHouse. The worker runs it
 * only under `make ui-full`; in plain `make ui` the events wait in the outbox,
 * durable, and are exported the first time ClickHouse is there. Creating the
 * analytics tables is a deployment step (yii3-ab-testing-clickhouse never does
 * it implicitly), so it happens here, once, before the first export.
 */
#[AsCommand(name: 'sandbox:ab:export', description: 'Export A/B exposures and conversions from the outbox to ClickHouse')]
final class AbExportCommand extends Command
{
    public function __construct(
        private readonly AnalyticsSchema $schema,
        private readonly ClickHouseOutboxExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->schema->install();

        $result = $this->exporter->export();
        $output->writeln(sprintf(
            'ab-export published=%d retry=%d failed=%d',
            $result->published,
            $result->retryScheduled,
            $result->terminalFailed,
        ));

        return Command::SUCCESS;
    }
}
