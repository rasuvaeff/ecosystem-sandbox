<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Shared\Inbox\Inbox;

final class InMemoryInbox implements Inbox
{
    /** @var array<string, true> */
    private array $handled = [];

    public function handled(string $consumer, string $messageId): bool
    {
        return isset($this->handled[$consumer . "\0" . $messageId]);
    }

    public function record(string $consumer, string $messageId): void
    {
        $this->handled[$consumer . "\0" . $messageId] = true;
    }
}
