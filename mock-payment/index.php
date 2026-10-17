<?php

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/health') {
    respond(200, ['status' => 'ok']);
}

// A stand-in for Google's siteverify, so the captcha gate is tested without
// the internet: the one token it accepts is `test-captcha-pass`.
if ($path === '/recaptcha/siteverify' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $passed = ($_POST['secret'] ?? '') !== '' && ($_POST['response'] ?? '') === 'test-captcha-pass';
    respond(200, $passed
        ? ['success' => true, 'hostname' => 'sandbox', 'challenge_ts' => gmdate('Y-m-d\\TH:i:s\\Z')]
        : ['success' => false, 'error-codes' => ['invalid-input-response']]);
}

// The bank's ledger, per idempotency key, in files: the built-in server runs
// one process per request, and a charge must outlive the request that made it.
$ledger = static fn (string $key, string $kind): string => sys_get_temp_dir() . '/payment-mock-' . $kind . '-' . hash('sha256', $key);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Reconciliation: what became of the charge made under this key.
if ($method === 'GET' && preg_match('#^/payments/([^/]+)$#', $path, $match) === 1) {
    $key = rawurldecode($match[1]);
    $charged = is_file($ledger($key, 'charge'));
    respond(200, ['key' => $key, 'state' => $charged ? 'succeeded' : 'not_found', 'amountCents' => $charged ? (int) file_get_contents($ledger($key, 'charge')) : 0, 'reference' => 'mock-' . substr(hash('sha256', $key), 0, 12)]);
}

// For the scenario that checks effects at the bank: the charges recorded
// under a key.
if ($method === 'GET' && preg_match('#^/ledger/([^/]+)$#', $path, $match) === 1) {
    $key = rawurldecode($match[1]);
    respond(200, ['key' => $key, 'charges' => is_file($ledger($key, 'charge')) ? 1 : 0]);
}

if ($path !== '/charge' || $method !== 'POST') {
    respond(404, ['error' => 'not_found']);
}

$delayMs = max(0, (int) (getenv('PAYMENT_MOCK_DELAY_MS') ?: 0));
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

if (getenv('PAYMENT_MOCK_UNAVAILABLE') === '1') {
    respond(503, ['error' => 'temporarily_unavailable']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$orderId = is_array($body) ? (string) ($body['orderId'] ?? '') : '';
$key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

if ($orderId === '' || $key === '') {
    respond(422, ['error' => 'orderId and Idempotency-Key are required']);
}

if (getenv('PAYMENT_MOCK_FAIL') === '1') {
    respond(402, ['approved' => false, 'reference' => 'mock-declined']);
}

// Test amounts, the way real payment sandboxes use test cards: the outcome is
// chosen by the request itself, so no endpoint can switch the gateway's
// behaviour. The PAYMENT_MOCK_* switches above keep priority.
$amountCents = is_array($body) ? (int) ($body['amountCents'] ?? 0) : 0;

if ($amountCents === 4002) {
    respond(402, ['approved' => false, 'reference' => 'mock-declined', 'error' => 'card_declined']);
}

// An outage is the bank's, not one card's: once started it fails every charge
// for its duration, the way a real outage hits every customer at once. Each
// payment starts at most one outage, so its own later retries can succeed.
$outageSeconds = [5031 => 12, 5030 => 45][$amountCents] ?? null;
$outageFile = sys_get_temp_dir() . '/payment-mock-outage-until';
$startedFile = sys_get_temp_dir() . '/payment-mock-outage-' . hash('sha256', $key);
if ($outageSeconds !== null && !is_file($startedFile)) {
    touch($startedFile);
    file_put_contents($outageFile, (string) (time() + $outageSeconds));
}
if (is_file($outageFile) && (int) file_get_contents($outageFile) > time()) {
    respond(503, ['error' => 'temporarily_unavailable']);
}

// 5040: the charge goes through and the reply is lost — every answer to
// this key is a 503, the retries included, while the money is taken once.
// Only reconciliation (GET /payments/{key}) finds out.
if ($amountCents === 5040) {
    if (!is_file($ledger($key, 'charge'))) {
        file_put_contents($ledger($key, 'charge'), (string) $amountCents);
    }
    respond(503, ['error' => 'temporarily_unavailable']);
}

if (!is_file($ledger($key, 'charge'))) {
    file_put_contents($ledger($key, 'charge'), (string) $amountCents);
}

respond(200, ['approved' => true, 'reference' => 'mock-' . $orderId]);

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
