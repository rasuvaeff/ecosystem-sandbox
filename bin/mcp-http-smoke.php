<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new Client(['http_errors' => false]);
$baseUrl = getenv('BASE_URL') ?: 'http://app:8080';
$secret = getenv('MCP_ENDPOINT_SECRET') ?: 'sandbox-mcp-secret';
$headers = [
    'Accept' => 'application/json, text/event-stream',
    'Content-Type' => 'application/json',
    'X-Mcp-Secret' => $secret,
];
$send = static function (array $payload, ?string $sessionId = null) use ($client, $baseUrl, $headers): array {
    $requestHeaders = $headers;
    if ($sessionId !== null) {
        $requestHeaders['Mcp-Session-Id'] = $sessionId;
    }
    $response = $client->sendRequest(new Request(
        method: 'POST',
        uri: $baseUrl . '/mcp',
        headers: $requestHeaders,
        body: json_encode($payload, JSON_THROW_ON_ERROR),
    ));
    $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body) || $response->getStatusCode() !== 200 || isset($body['error'])) {
        throw new RuntimeException('MCP HTTP request failed: ' . (string) $response->getBody());
    }

    return [$response, $body];
};

[$initializeResponse] = $send([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'sandbox-http-smoke', 'version' => '1.0'],
    ],
]);
$sessionId = $initializeResponse->getHeaderLine('Mcp-Session-Id');
if ($sessionId === '') {
    throw new RuntimeException('MCP initialize did not return a session id');
}

[, $list] = $send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sessionId);
[, $call] = $send([
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => ['name' => 'order.status', 'arguments' => ['orderId' => 'http-mcp-demo']],
], $sessionId);

$tools = $list['result']['tools'] ?? [];
$text = $call['result']['content'][0]['text'] ?? '';
if (!is_array($tools) || count($tools) !== 3 || !str_contains((string) $text, 'http-mcp-demo')) {
    throw new RuntimeException('MCP HTTP tools/list or tools/call response was invalid');
}

// The real endpoint audits too: the call is a row credited to MCP_USER_ID.
$appContainer = require __DIR__ . '/support/container.php';
$permitted = $appContainer->get(App\Domain\Assistant\AgentIdentities::class)->permitted;
$trail = $appContainer->get(App\Infrastructure\Assistant\Audit\DbToolCallAudit::class)->latestFor(userId: $permitted, since: gmdate('Y-m-d H:i:s', time() - 60), limit: 10);
if (!in_array('http-mcp-demo', array_map(static fn (App\Application\Assistant\GetTranscript\AuditedCall $row): mixed => $row->arguments->orderId ?? null, $trail), true)) {
    throw new RuntimeException('the /mcp call left no audit row: ' . json_encode($trail));
}

echo 'MCP HTTP PASS: session=' . $sessionId . ' tools=' . count($tools) . " call=order.status secret=protected audit=recorded\n";
