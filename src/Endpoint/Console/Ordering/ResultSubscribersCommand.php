<?php

declare(strict_types=1);

namespace App\Endpoint\Console\Ordering;

use App\Application\Ordering\Integration\OrderPaid;
use App\Application\Ordering\Integration\OrderPaymentFailed;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sandbox:projections:run', description: 'Deliver order results to their subscribers (realtime, webhooks, analytics)')]
final class ResultSubscribersCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly PublisherInterface $publisher,
        private readonly ClockInterface $clock,
        private readonly RetryPolicy $retryPolicy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            // The same policy the payment Processor uses, so
            // OUTBOX_RETRY_DELAY_SECONDS and maxAttempts mean one thing for
            // every outbox consumer.
            retryPolicy: $this->retryPolicy,
            clock: $this->clock,
            types: [OrderPaid::type(), OrderPaymentFailed::type()],
        )->process();
        $output->writeln(sprintf('published=%d failed=%d skipped=%d', $result->published, $result->failed, $result->skipped));

        return Command::SUCCESS;
    }
}
