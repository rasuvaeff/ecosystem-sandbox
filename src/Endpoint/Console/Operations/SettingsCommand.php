<?php

declare(strict_types=1);

namespace App\Endpoint\Console\Operations;

use Rasuvaeff\Yii3Settings\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sandbox:settings:show', description: 'Show typed payment runtime settings')]
final class SettingsCommand extends Command
{
    public function __construct(private readonly Settings $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([
            'gateway.mode=' . $this->settings->string(key: 'payment.gateway_mode'),
            'gateway.timeout_ms=' . $this->settings->int(key: 'payment.gateway_timeout_ms'),
            'gateway.connect_timeout_ms=' . $this->settings->int(key: 'payment.gateway_connect_timeout_ms'),
        ]);

        return Command::SUCCESS;
    }
}
