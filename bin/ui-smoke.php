<?php

declare(strict_types=1);

/**
 * The web UI end to end, against the `ui` profile's background worker:
 *
 * - the page and its assets are served, and `/` leads to it;
 * - every text key the page asks for exists in both languages;
 * - each test amount reaches the final state the page promises, read from the
 *   same evidence endpoint the page polls;
 * - a short bank outage still ends `paid` (the worker's retry delay outlasts the
 *   breaker cooldown), a long one fails every payment made during it, and
 *   payments go through again afterwards.
 */

use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$baseUrl = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$client = new Client(['base_uri' => $baseUrl, 'http_errors' => false, 'allow_redirects' => false]);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "UI SMOKE FAIL: {$message}\n");
        exit(1);
    }
}

$root = $client->get('/');
check($root->getStatusCode() === 302 && $root->getHeaderLine('Location') === '/ui/', '/ must redirect to /ui/');
$page = $client->get('/ui/');
check($page->getStatusCode() === 200 && str_contains((string) $page->getBody(), 'data-ui-root'), '/ui/ must serve the page');
foreach (array_map('basename', [...glob(dirname(__DIR__) . '/public/ui/*.js'), dirname(__DIR__) . '/public/ui/styles.css']) as $asset) {
    check($client->get('/ui/' . $asset)->getStatusCode() === 200, "/ui/{$asset} must be served");
}
// Every page is rendered with its own head (yii3-seo): the title, the
// description, the canonical URL and the link preview are the page's, and
// the personal order page alone is kept out of search engines.
foreach (App\Endpoint\Web\Ui\UiPage::cases() as $uiPage) {
    $response = $client->get($uiPage->path());
    $html = (string) $response->getBody();
    check($response->getStatusCode() === 200 && str_contains($html, 'data-ui-root'), "{$uiPage->path()} must render");
    check(str_contains($html, '<title>' . htmlspecialchars($uiPage->title(), ENT_QUOTES) . ' | rasuvaeff/* sandbox</title>'), "{$uiPage->path()} must carry its own title");
    check(str_contains($html, 'name="description" content="' . htmlspecialchars($uiPage->description(), ENT_QUOTES) . '"'), "{$uiPage->path()} must carry its description");
    $canonical = preg_match('#<link\b(?=[^>]*\brel="canonical")[^>]*\bhref="(https?://[^"]+)"#', $html, $link) === 1 ? $link[1] : '';
    check(str_ends_with($canonical, $uiPage->path()), "{$uiPage->path()} must carry an absolute canonical URL");
    check(str_contains($html, 'property="og:title"') && str_contains($html, 'application/ld+json'), "{$uiPage->path()} must carry Open Graph and JSON-LD");
    check(str_contains($html, 'name="robots" content="noindex') === $uiPage->isPersonal(), "{$uiPage->path()}: only the personal page is noindex");
    check(str_contains($html, '<script src="' . $uiPage->script() . '"></script>'), "{$uiPage->path()} must load its script");
}
check($client->get('/ui/no-such-page.html')->getStatusCode() === 404, 'an unknown page must be a 404');
check($client->get('/ui/nosuchpage.html')->getStatusCode() === 404, 'a page name the UI does not have must be a 404');
$unknown = $client->get('/no/such/route');
check($unknown->getStatusCode() === 404 && str_starts_with($unknown->getHeaderLine('Content-Type'), 'application/problem+json'), 'a URL no route matches must be a 404 problem, not a 500');
$robots = $client->get('/robots.txt');
check($robots->getStatusCode() === 200 && str_contains((string) $robots->getBody(), 'Disallow: /'), 'robots.txt must keep an undeployed sandbox out of search engines');

$publicUi = dirname(__DIR__) . '/public/ui';
/** @var array<string, array<string, string>> $dictionary */
$dictionary = json_decode((string) file_get_contents($publicUi . '/i18n.json'), true, 512, JSON_THROW_ON_ERROR);
check(array_keys($dictionary) === ['ru', 'en'], 'i18n.json must hold ru and en');
check(array_diff_key($dictionary['ru'], $dictionary['en']) === [] && array_diff_key($dictionary['en'], $dictionary['ru']) === [], 'ru and en must have the same keys');

$required = [];
foreach ([...glob(dirname(__DIR__) . '/resources/views/*/*.php'), ...glob($publicUi . '/*.js'), dirname(__DIR__) . '/src/Endpoint/Web/Ui/UiPage.php'] as $file) {
    $source = (string) file_get_contents($file);
    preg_match_all('/data-i18n="([^"]+)"/', $source, $htmlKeys);
    // A literal key only: the layout prints UiPage::titleKey(), listed below.
    preg_match_all('/data-title-key="([a-z][a-z0-9._-]*)"/', $source, $titleKeys);
    // A literal key only: `t('step.' + step.key ...)` is covered by the list below.
    preg_match_all("/\\bt\\('([^']+)'\\s*[,)]/", $source, $scriptKeys);
    preg_match_all("/showMessage\\('([^']+)'/", $source, $messageKeys);
    preg_match_all("/href: '[^']+', key: '([^']+)'/", $source, $navKeys);
    $required = [...$required, ...$htmlKeys[1], ...$titleKeys[1], ...$scriptKeys[1], ...$messageKeys[1], ...$navKeys[1]];
}
foreach (App\Endpoint\Web\Ui\UiPage::cases() as $uiPage) {
    $required[] = $uiPage->titleKey();
}
// Keys the scripts build from a value the API returns.
foreach (['accepted', 'queued', 'bank', 'paid', 'declined', 'abandoned', 'result', 'notify'] as $step) {
    $required[] = "step.{$step}.title";
    $required[] = "step.{$step}.text";
}
// The tests page builds a key from the layer the API returns.
foreach (['Domain', 'Application', 'Infrastructure', 'Architecture', 'Other'] as $layer) {
    $required[] = "tests.layer.{$layer}";
    $required[] = "tests.layer.{$layer}.text";
}
foreach (['waiting', 'active', 'done', 'failed'] as $status) {
    $required[] = "status.{$status}";
}
$workflow = json_decode((string) $client->get('/api/v1/workflows/order')->getBody(), true, 512, JSON_THROW_ON_ERROR);
foreach ($workflow['places'] as $place) {
    $required[] = "place.{$place}";
}
foreach (['closed', 'open', 'probe', 'half-open'] as $mode) {
    $required[] = "breaker.state.{$mode}";
    $required[] = "breaker.text.{$mode}";
}
$operator = json_decode((string) $client->get('/api/v1/operator')->getBody(), true, 512, JSON_THROW_ON_ERROR);
foreach (['enabled', 'disabled', 'kill_switch', 'rollout_excluded', 'environment_excluded', 'forced', 'unknown'] as $reason) {
    $required[] = "operator.reason.{$reason}";
}
foreach ($operator['settings'] as $setting) {
    $required[] = 'operator.setting.' . $setting['key'];
}
$health = json_decode((string) $client->get('/health')->getBody(), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($health['checks']) as $check) {
    $required[] = "operator.health.check.{$check}";
}
$missing = array_values(array_diff(array_unique($required), array_keys($dictionary['ru'])));
check($missing === [], 'text keys missing from i18n.json: ' . implode(', ', $missing));

/**
 * @param array<string, string> $headers
 */
function place(Client $client, string $orderId, int $amountCents, array $headers = [], int $expectedStatus = 202): void
{
    $response = $client->post('/api/v1/checkout', [
        // Hashed: the header admits no spaces, and an odd order id below has them.
        // The key differs per expectation, so a deliberate replay is a new request.
        'headers' => ['Content-Type' => 'application/json', 'Idempotency-Key' => 'ui-smoke-' . md5($orderId . $expectedStatus)] + $headers,
        'body' => json_encode(['orderId' => $orderId, 'customerId' => 'ui-visitor', 'amountCents' => $amountCents], JSON_THROW_ON_ERROR),
    ]);
    check($response->getStatusCode() === $expectedStatus, "checkout of {$orderId} must answer {$expectedStatus}, got " . $response->getStatusCode());
}

/**
 * @param array<string, string> $headers
 *
 * @return array<string, mixed> the evidence once the order settled
 */
function settle(Client $client, string $orderId, int $amountCents, int $timeoutSeconds, array $headers = []): array
{
    place($client, $orderId, $amountCents, $headers);

    return awaitSettled($client, $orderId, $timeoutSeconds);
}

/**
 * Waits without posting again: a second checkout of a settled order is a 409.
 *
 * @return array<string, mixed>
 */
function awaitSettled(Client $client, string $orderId, int $timeoutSeconds): array
{
    $deadline = time() + $timeoutSeconds;
    do {
        sleep(1);
        $evidence = json_decode((string) $client->get('/api/v1/orders/' . rawurlencode($orderId) . '/evidence')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $settled = in_array($evidence['order']['status'], ['paid', 'payment_failed'], true)
            && array_filter($evidence['outbox'], static fn (array $row): bool => in_array($row['type'], ['order.paid', 'order.payment_failed'], true)) !== [];
    } while (!$settled && time() < $deadline);

    check($settled, "{$orderId} did not settle within {$timeoutSeconds}s: " . json_encode($evidence, JSON_THROW_ON_ERROR));

    return $evidence;
}

/** @return array<string, mixed> */
function paymentJob(array $evidence): array
{
    foreach ($evidence['outbox'] as $row) {
        if ($row['type'] === 'payment.requested') {
            return $row;
        }
    }
    check(false, 'evidence holds no payment.requested row');

    return [];
}

$run = 'ui-smoke-' . time();
$results = [];

$ok = settle($client, "{$run}-ok", 1999, 30);
check($ok['order']['status'] === 'paid', 'the ordinary card must end paid');
$results[] = 'ok=paid';

// yii3-filestorage: the paid order gets exactly one receipt although the
// fan-out redelivers it (the webhook refuses the first attempt), downloadable
// through its signed URL only.
$receipt = null;
for ($attempt = 0; $attempt < 30 && $receipt === null; ++$attempt) {
    $receipt = json_decode((string) $client->get('/api/v1/orders/' . rawurlencode($ok['order']['id']) . '/evidence')->getBody(), true, 512, JSON_THROW_ON_ERROR)['receipt'];
    $receipt ?? sleep(1);
}
check(is_array($receipt) && is_string($receipt['url']), 'a paid order must get a receipt file');
$download = $client->get($receipt['url']);
check($download->getStatusCode() === 200 && str_contains((string) $download->getBody(), $ok['order']['id']), 'the signed receipt URL must serve the receipt');
check(str_starts_with($download->getHeaderLine('Content-Disposition'), 'attachment'), 'the receipt must download, not open inline, got "' . $download->getHeaderLine('Content-Disposition') . '"');
check($client->get(substr($receipt['url'], 0, -1) . (str_ends_with($receipt['url'], 'A') ? 'B' : 'A'))->getStatusCode() === 404, 'a tampered receipt URL must be a 404');
$appContainer = require __DIR__ . '/support/container.php';
// Past the webhook retry, which is when a second receipt would have appeared.
sleep(12);
$links = $appContainer->get(Rasuvaeff\Yii3FilestorageAttachments\Attachments::class)->forOwner(ownerType: 'order', ownerId: $ok['order']['id'], role: 'receipt');
check(count($links) === 1, 'the redelivered result must not write a second receipt, found ' . count($links));
$results[] = 'receipt=1(signed)';

// The order page's backend: one answer carries the same evidence the evidence
// endpoint gives, and no breaker once the order settled.
$screen = json_decode((string) $client->get('/api/v1/ui/orders/' . rawurlencode($ok['order']['id']))->getBody(), true, 512, JSON_THROW_ON_ERROR);
$evidenceNow = json_decode((string) $client->get('/api/v1/orders/' . rawurlencode($ok['order']['id']) . '/evidence')->getBody(), true, 512, JSON_THROW_ON_ERROR);
check(($screen['evidence'] ?? null) == $evidenceNow, 'the order screen must carry the evidence endpoint\'s answer');
check(array_key_exists('breaker', $screen) && $screen['breaker'] === null, 'a settled order\'s screen must carry no breaker');
$results[] = 'screen=1 request';

// The result fan-out: Centrifugo got the publication, the webhook receiver
// refused the first attempt (WEBHOOK_FAIL_FIRST) and took the retry, signed.
$history = json_decode((string) new Client(['http_errors' => false])->post('http://centrifugo:9000/api/history', [
    'headers' => ['X-API-Key' => 'sandbox-centrifugo-key'],
    'json' => ['channel' => 'orders:' . $ok['order']['id'], 'limit' => 5],
])->getBody(), true, 512, JSON_THROW_ON_ERROR);
$published = array_column(array_column($history['result']['publications'] ?? [], 'data'), 'status');
check(in_array('paid', $published, true), 'Centrifugo history of the paid order must hold its publication');
// The event row exists before the fan-out delivered it, and the retry after
// the receiver's deliberate 500 comes OUTBOX_RETRY_DELAY_SECONDS (10 s for the
// ui worker) plus one worker loop later; a slow host gets twice that.
$retried = [];
for ($attempt = 0; $attempt < 40 && $retried === []; ++$attempt) {
    $webhooks = json_decode((string) $client->get('/api/v1/webhooks')->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $received = [];
    foreach ($webhooks['received'] ?? [] as $entry) {
        $received[$entry['eventId']][] = $entry['status'] . ($entry['signatureValid'] ? '' : '!');
    }
    $retried = array_filter($received, static fn (array $answers): bool => in_array(500, array_map('intval', $answers), true) && in_array('204', $answers, true));
    if ($retried === []) {
        sleep(1);
    }
}
check($retried !== [], 'the webhook receiver must show a signed event refused once and taken on retry');
$sentFailed = array_filter($webhooks['deliveries'], static fn (array $row): bool => $row['status'] === 'failed' && $row['lastError'] === 'HTTP 500');
check($sentFailed !== [], 'the sender must record the refused webhook attempt');
$results[] = 'fan-out=centrifugo+webhook(retry)';

// One trace from the checkout request to the bank call.
$kinds = [];
for ($attempt = 0; $attempt < 15 && !in_array('consumer', $kinds, true); ++$attempt) {
    sleep(1);
    $trace = json_decode((string) $client->get('/api/v1/orders/' . rawurlencode($ok['order']['id']) . '/trace')->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $kinds = array_column($trace['spans'], 'kind');
}
check(in_array('server', $kinds, true) && in_array('consumer', $kinds, true) && in_array('client', $kinds, true), 'the checkout trace must hold the server, consumer and client spans: ' . implode(',', $kinds));
$results[] = 'trace=' . implode('>', $kinds);

// rasuvaeff/context-http: the trace read runs under a request budget. A
// caller shortens it, never extends it; an exhausted one is a 504; the
// request keeps the one correlation id CorrelationIdMiddleware minted.
$tracePath = '/api/v1/orders/' . rawurlencode($ok['order']['id']) . '/trace';
check($trace['budget']['limitMs'] !== null && $trace['budget']['limitMs'] <= 5000, 'the trace read must run under the 5 s default budget: ' . json_encode($trace['budget']));
$short = json_decode((string) $client->get($tracePath, ['headers' => ['X-Request-Timeout-Ms' => '800']])->getBody(), true, 512, JSON_THROW_ON_ERROR)['budget'];
$long = json_decode((string) $client->get($tracePath, ['headers' => ['X-Request-Timeout-Ms' => '99999999']])->getBody(), true, 512, JSON_THROW_ON_ERROR)['budget'];
check($short['limitMs'] <= 800 && $long['limitMs'] <= 5000, 'a caller may shorten the budget but never extend it: ' . json_encode([$short, $long]));
check($client->get($tracePath, ['headers' => ['X-Request-Timeout-Ms' => '1']])->getStatusCode() === 504, 'an exhausted budget must answer 504');
$forged = $client->get($tracePath, ['headers' => ['X-Request-ID' => 'client-forged-id']]);
check($forged->getHeaderLine('X-Request-ID') !== 'client-forged-id', 'the budget must not let a caller pick the correlation id');
$results[] = 'budget=5000ms(shorten-only,504)';

// `make ui` has no ClickHouse and the page must say so; under `make ui-full`
// it is there and the page must not pretend otherwise.
$clickHouseUp = @fsockopen('clickhouse', 8123, $errno, $errstr, 1) !== false;
$analytics = json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR);
check($analytics['available'] === $clickHouseUp, 'analytics must report ClickHouse ' . ($clickHouseUp ? 'on' : 'off'));
$results[] = 'analytics=' . ($clickHouseUp ? 'on' : 'off');

// yii3-utm: the campaign travels as X-Utm-* headers, the checkout journals it
// in the order's transaction, the story names it and the campaign report
// counts the paid order once, even after the checkout is replayed.
$campaign = 'ui-smoke-' . $run;
$utmHeaders = [
    'X-Utm-Source' => 'newsletter',
    'X-Utm-Medium' => 'email',
    'X-Utm-Campaign' => $campaign,
    'X-Utm-Landing-Page' => 'http://127.0.0.1:8080/ui/?utm_campaign=' . $campaign . '&token=must-not-be-kept',
];
$utm = settle($client, "{$run}-utm", 1999, 30, $utmHeaders);
place($client, "{$run}-utm", 1999, $utmHeaders, 409);
check(($utm['attribution'][0]['channel'] ?? null) === 'email' && $utm['attribution'][0]['campaign'] === $campaign, 'the order story must name the campaign it was attributed to: ' . json_encode($utm['attribution']));
check(count($utm['attribution']) === 1 && !str_contains((string) $utm['attribution'][0]['landingPage'], 'token='), 'one attribution row, with the landing page stripped of foreign parameters');
$report = json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR);
$row = array_values(array_filter($report['campaigns'], static fn (array $row): bool => $row['campaign'] === $campaign));
check(count($row) === 1 && $row[0]['orders'] === 1 && $row[0]['paidOrders'] === 1 && $row[0]['paidAmountCents'] === 1999, 'the campaign report must count the paid order once: ' . json_encode($row));
$plain = $client->get('/api/v1/orders/' . rawurlencode("{$run}-ok") . '/evidence');
check(json_decode((string) $plain->getBody(), true, 512, JSON_THROW_ON_ERROR)['attribution'] === [], 'an order placed without a campaign has no attribution');
$results[] = 'utm=email(once)';

// yii3-settings-ui over yii3-settings-db: a value saved on the admin page is
// what the application reads next (DB row > config > default), and a reset
// gives the configured value back. The flag page lists the seeded flag;
// simulate-kill-switch flips it through the same form.
check($client->get('/admin/flags')->getStatusCode() === 200 && str_contains((string) $client->get('/admin/flags')->getBody(), 'payments.enabled'), 'the flag admin page must list payments.enabled');
$settingValue = static function (Client $client): mixed {
    foreach (json_decode((string) $client->get('/api/v1/operator')->getBody(), true, 512, JSON_THROW_ON_ERROR)['settings'] as $setting) {
        if ($setting['key'] === 'payment.gateway_connect_timeout_ms') {
            return $setting['value'];
        }
    }

    return null;
};
$configured = $settingValue($client);
$saved = $client->post('/admin/settings/payment.gateway_connect_timeout_ms', ['form_params' => ['Setting' => ['value' => '1234']], 'allow_redirects' => false]);
check($saved->getStatusCode() === 302 && $settingValue($client) === 1234, 'a setting saved on the admin page must be what the application reads');
$client->post('/admin/settings/payment.gateway_connect_timeout_ms/reset', ['allow_redirects' => false]);
check($settingValue($client) === $configured, 'a reset must give the configured value back');
$results[] = 'admin=settings(saved,reset)';

// yii3-ab-testing: the visitor gets a variant and a signed receipt; the
// exposure is an outbox row, waiting for the exporter `make ui-full` runs.
$experiment = json_decode((string) $client->get('/api/v1/experiments/checkout-button')->getBody(), true, 512, JSON_THROW_ON_ERROR);
check(in_array($experiment['variant'] ?? null, ['control', 'green'], true) && is_string($experiment['receipt'] ?? null), 'the pay-button experiment must assign a variant with a receipt');
$waiting = json_decode((string) $client->get('/api/v1/analytics')->getBody(), true, 512, JSON_THROW_ON_ERROR)['experiment'];
check($clickHouseUp || $waiting['pending'] >= 1, 'without ClickHouse the exposure must wait in the outbox: ' . json_encode($waiting));
$results[] = 'ab=' . $experiment['variant'] . ($clickHouseUp ? '' : '(pending=' . $waiting['pending'] . ')');

// The schema admits any 1-64 character id; the gateway key is derived from it,
// so an id with spaces and non-ASCII must pay like any other.
$odd = settle($client, "{$run} заказ ✓ (odd id)", 1999, 30);
check($odd['order']['status'] === 'paid', 'an order whose id holds spaces and unicode must end paid');
$results[] = 'odd-id=paid';

$declined = settle($client, "{$run}-declined", 4002, 30);
check($declined['order']['status'] === 'payment_failed' && paymentJob($declined)['status'] === 'published', 'a decline must fail the order without exhausting the job');
check(paymentJob($declined)['attempts'] === 1, 'a decline must not be retried by the outbox');
$results[] = 'declined=payment_failed(attempts=1)';

// A short outage outlasts the in-pipeline retries and opens the breaker; the
// worker's retry delay outlasts the breaker cooldown, so the payment still
// lands once the bank is back.
$blip = settle($client, "{$run}-blip", 5031, 60);
check($blip['order']['status'] === 'paid', 'a payment caught by a short outage must end paid');
check(paymentJob($blip)['attempts'] >= 2, 'a payment caught by a short outage must have been retried by the outbox');
$results[] = 'blip=paid(attempts=' . paymentJob($blip)['attempts'] . ')';

// A long outage is the bank's, not one card's: an ordinary payment placed
// while it lasts fails as well, exactly as the page says.
$outageId = "{$run}-outage";
place($client, $outageId, 5030);
sleep(2);
$during = settle($client, "{$run}-during-outage", 1999, 60);
$outage = awaitSettled($client, $outageId, 60);
check($outage['order']['status'] === 'payment_failed', 'a long outage must end payment_failed');
check(paymentJob($outage)['status'] === 'failed' && paymentJob($outage)['attempts'] === $outage['maxAttempts'], 'a long outage must exhaust the payment job');
check($during['order']['status'] === 'payment_failed', 'an ordinary payment placed during a long outage must fail with it');
$results[] = 'outage=payment_failed(attempts=' . paymentJob($outage)['attempts'] . ')';
$results[] = 'during-outage=payment_failed';

// Once the outage and the breaker cooldown are over, payments go through again.
sleep(45);
$after = settle($client, "{$run}-after-outage", 1999, 60);
check($after['order']['status'] === 'paid', 'a payment after the outage must end paid');
$results[] = 'after-outage=paid';

echo 'UI SMOKE PASS: page, ' . count(array_unique($required)) . ' text keys in ru/en, ' . implode(' ', $results) . "\n";
