<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Webhook;

use App\Application\Ordering\GetWebhookDeliveries\WebhookJournal;
use App\Domain\Shared\TableNames;
use App\Environment;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Yii3OutboxWebhooksBridge\UrlMasker;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * The sender's side is yii3-webhooks-db's delivery table; the receiver's is
 * the webhook mock's journal. Endpoint URLs are masked the way the bridge
 * masks them in logs; secrets never leave.
 */
final readonly class DbWebhookJournal implements WebhookJournal
{
    public function __construct(
        private ConnectionInterface $db,
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
    ) {}

    public function sent(int $limit): array
    {
        $deliveries = [];
        /** @var array{event_id: string, event_type: string, endpoint_url: string, status: string, attempts: int|string, last_error: ?string, created_at: string} $row */
        foreach (new Query(db: $this->db)
            ->select(columns: ['event_id', 'event_type', 'endpoint_url', 'status', 'attempts', 'last_error', 'created_at'])
            ->from(tables: TableNames::WEBHOOK_DELIVERIES)
            ->orderBy(columns: ['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(limit: $limit)
            ->all() as $row) {
            $deliveries[] = [
                'eventId' => $row['event_id'],
                'type' => $row['event_type'],
                'endpoint' => UrlMasker::mask(url: $row['endpoint_url']),
                'status' => $row['status'],
                'attempts' => (int) $row['attempts'],
                // A transport error message carries the full request URI; the
                // bridge scrubs it for the log, and so does this view.
                'lastError' => $row['last_error'] === null ? null : UrlMasker::scrub(text: $row['last_error'], url: $row['endpoint_url']),
                'createdAt' => $row['created_at'],
            ];
        }

        return $deliveries;
    }

    public function received(): ?array
    {
        try {
            $answer = $this->client->sendRequest($this->requestFactory->createRequest(
                'GET',
                Environment::string(name: 'WEBHOOK_MOCK_BASE_URL', default: 'http://webhook-mock:8082') . '/deliveries',
            ));
        } catch (\Throwable) {
            // The receiver is a separate service; when it is down the sender's
            // records still stand on their own.
            return null;
        }
        $parsed = Schema::object(['received' => Schema::arrayOf(Schema::json())])->strip()->parse(input: json_decode(json: (string) $answer->getBody(), associative: true));
        /** @var array{received: list<mixed>} $value */
        $value = $parsed->value;

        return $parsed->ok ? $value['received'] : null;
    }
}
