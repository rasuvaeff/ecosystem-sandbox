<?php

declare(strict_types=1);

namespace App\Endpoint\Console\Payments;

use Rasuvaeff\Yii3Outbox\ProcessingResult;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Telemetry\QueueTracing;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sandbox:outbox:run', description: 'Process payment requests from the outbox')]
final class PaymentWorkerCommand extends Command
{
    public function __construct(
        private readonly Processor $processor,
        private readonly QueueTracing $queueTracing,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->queueTracing->consume(
            metadata: [],
            name: 'process outbox.payment',
            handler: fn (): ProcessingResult => $this->processor->process(),
            attributes: ['messaging.system' => 'postgres-outbox', 'messaging.destination.name' => 'outbox'],
        );
        $this->writeResult($output, $result);

        return Command::SUCCESS;
    }

    private function writeResult(OutputInterface $output, ProcessingResult $result): void
    {
        $output->writeln(sprintf(
            'published=%d failed=%d skipped=%d',
            $result->published,
            $result->failed,
            $result->skipped,
        ));
    }
}
