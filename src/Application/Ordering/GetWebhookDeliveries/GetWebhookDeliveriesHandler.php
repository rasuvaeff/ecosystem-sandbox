<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetWebhookDeliveries;

final readonly class GetWebhookDeliveriesHandler
{
    public const int LIMIT = 30;

    public function __construct(private WebhookJournal $journal) {}

    /**
     * @return array{deliveries: list<array<string, mixed>>, received: list<mixed>|null}
     */
    public function handle(): array
    {
        return ['deliveries' => $this->journal->sent(limit: self::LIMIT), 'received' => $this->journal->received()];
    }
}
