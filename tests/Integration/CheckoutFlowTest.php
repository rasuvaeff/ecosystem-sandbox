<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

#[Test]
#[CoversNothing]
final class CheckoutFlowTest
{
    public function tenantMiddlewareResolvesHeaderIntoRequestAttribute(): void
    {
        $baseUrl = getenv('SANDBOX_BASE_URL') ?: 'http://app:8080';
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "X-Tenant-Id: acme\r\n"]]);
        $body = file_get_contents($baseUrl . '/api/v1/tenant', use_include_path: false, context: $context);
        if ($body === false) {
            throw new \RuntimeException(message: 'Sandbox tenant endpoint is unavailable');
        }
        /** @var array<string, mixed> $json */
        $json = json_decode($body, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        Assert::same($json['id'] ?? null, expected: 'acme');
        Assert::same($json['status'] ?? null, expected: 'active');
    }

    public function repeatedCheckoutKeyProducesOneOutboxEvent(): void
    {
        $baseUrl = getenv('SANDBOX_BASE_URL') ?: 'http://app:8080';
        $orderId = 'testo-' . bin2hex(random_bytes(length: 4));
        $payload = json_encode([
            'orderId' => $orderId,
            'customerId' => 'testo-customer',
            'amountCents' => 1200,
        ], JSON_THROW_ON_ERROR);
        $headers = "Content-Type: application/json\r\nIdempotency-Key: testo-{$orderId}\r\n";
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $payload,
            'ignore_errors' => true,
        ]]);
        $first = file_get_contents($baseUrl . '/api/v1/checkout', use_include_path: false, context: $context);
        $second = file_get_contents($baseUrl . '/api/v1/checkout', use_include_path: false, context: $context);

        if ($first === false || $second === false) {
            throw new \RuntimeException(message: 'Sandbox HTTP endpoint is unavailable');
        }

        /** @var array<string, mixed> $firstJson */
        $firstJson = json_decode($first, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $secondJson */
        $secondJson = json_decode($second, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        Assert::same($firstJson['orderId'] ?? null, $orderId);
        Assert::same($secondJson['orderId'] ?? null, $orderId);

        $db = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: 'postgres',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'sandbox',
            ),
            getenv('DB_USER') ?: 'sandbox',
            getenv('DB_PASSWORD') ?: 'sandbox',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $statement = $db->prepare(query: 'SELECT COUNT(*) FROM outbox WHERE type = :type AND payload LIKE :order');
        $statement->execute(['type' => 'payment.requested', 'order' => '%"orderId":"' . $orderId . '"%']);

        Assert::same((int) $statement->fetchColumn(), expected: 1);
    }
}
