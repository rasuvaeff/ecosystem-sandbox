<?php

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/health') {
    respond(200, ['status' => 'ok']);
}

// What this receiver got, newest first: the web UI shows it next to the
// sender's own delivery records. Only ids, types and outcomes are kept.
$journal = sys_get_temp_dir() . '/sandbox-webhook-journal.jsonl';
if ($path === '/deliveries' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $lines = is_file($journal) ? file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $entries = array_map(static fn (string $line): mixed => json_decode($line, true), array_slice($lines, -50));
    respond(200, ['received' => array_reverse($entries)]);
}

if ($path !== '/webhook' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(404, ['error' => 'not_found']);
}

$payload = (string) file_get_contents('php://input');
$eventId = (string) ($_SERVER['HTTP_X_WEBHOOK_ID'] ?? '');
$signatureHeader = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
$secret = (string) (getenv('WEBHOOK_SECRET') ?: 'sandbox-webhook-secret');

if (!preg_match('/\At=(\d+),v1=([a-f0-9]+)\z/', $signatureHeader, $matches) || $eventId === '') {
    respond(400, ['error' => 'invalid_signature_header']);
}

$timestamp = (int) $matches[1];
$canonical = strlen($eventId) . '.' . $eventId . '.' . $timestamp . '.' . strlen($payload) . '.' . $payload;
$expected = hash_hmac('sha256', $canonical, $secret);

if (!hash_equals($expected, $matches[2])) {
    record($journal, $eventId, 401, false);
    respond(401, ['error' => 'invalid_signature']);
}

$failFile = sys_get_temp_dir() . '/sandbox-webhook-failed-' . hash('sha256', $eventId);
if (getenv('WEBHOOK_FAIL_FIRST') === '1' && !is_file($failFile)) {
    file_put_contents($failFile, '1');
    record($journal, $eventId, 500, true);
    respond(500, ['error' => 'temporary_receiver_failure']);
}

record($journal, $eventId, 204, true);
respond(204, []);

function record(string $journal, string $eventId, int $status, bool $signatureValid): void
{
    file_put_contents($journal, json_encode([
        'eventId' => $eventId,
        'type' => (string) ($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? ''),
        'status' => $status,
        'signatureValid' => $signatureValid,
        'at' => gmdate('Y-m-d\\TH:i:s\\Z'),
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');

    if ($payload !== []) {
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    }

    exit;
}
