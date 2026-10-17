<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Shared\Integration\IntegrationEvent;
use App\Application\Shared\Integration\IntegrationEventBus;

final class RecordingBus implements IntegrationEventBus
{
    /** @var list<IntegrationEvent> */
    public array $published = [];

    public function publish(IntegrationEvent $event): void
    {
        $this->published[] = $event;
    }
}
