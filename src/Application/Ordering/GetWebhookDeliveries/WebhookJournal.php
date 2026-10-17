<?php

declare(strict_types=1);

namespace App\Application\Ordering\GetWebhookDeliveries;

/**
 * Both ends of the order-result webhook: what the sender recorded per
 * attempt, and what the receiver says it got (null when it is down).
 */
interface WebhookJournal
{
    /**
     * @return list<array{eventId: string, type: string, endpoint: string, status: string, attempts: int, lastError: ?string, createdAt: string}>
     */
    public function sent(int $limit): array;

    /**
     * @return list<mixed>|null
     */
    public function received(): ?array;
}
