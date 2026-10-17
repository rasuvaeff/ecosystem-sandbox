<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\OpenApiContract\Contract;

require dirname(__DIR__) . '/vendor/autoload.php';

$baseUrl = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
// The document generated from the actions' OpenAPI attributes, the same one
// the running application validates the checkout against.
/** @var Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/support/container.php';
$contract = $container->get(Contract::class);
\assert($contract instanceof Contract);
$client = new Client(['http_errors' => false]);
$orderId = 'contract-' . time();
$requestBody = json_encode([
    'orderId' => $orderId,
    'customerId' => 'contract-customer',
    'amountCents' => 3210,
], JSON_THROW_ON_ERROR);

$checkoutRequest = new Request(
    method: 'POST',
    uri: $baseUrl . '/api/v1/checkout',
    headers: [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Idempotency-Key' => 'contract-' . $orderId,
    ],
    body: $requestBody,
);
$checkoutResponse = $client->sendRequest($checkoutRequest);
$contract->validateExchange($checkoutRequest, $checkoutResponse)->assertValid();

if ($checkoutResponse->getStatusCode() !== 202) {
    throw new RuntimeException('Expected checkout HTTP 202');
}

$orderRequest = new Request('GET', $baseUrl . '/api/v1/orders/' . $orderId, ['Accept' => 'application/json']);
$orderResponse = $client->sendRequest($orderRequest);
$contract->validateExchange($orderRequest, $orderResponse)->assertValid();

$timelineRequest = new Request('GET', $baseUrl . '/api/v1/orders/' . $orderId . '/timeline', ['Accept' => 'application/json']);
$timelineResponse = $client->sendRequest($timelineRequest);
$contract->validateExchange($timelineRequest, $timelineResponse)->assertValid();
$timeline = json_decode((string) $timelineResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($timeline) || $timeline['events'] === []) {
    throw new RuntimeException('Timeline of an accepted order must contain the start_payment transition');
}

$evidenceRequest = new Request('GET', $baseUrl . '/api/v1/orders/' . $orderId . '/evidence', ['Accept' => 'application/json']);
$evidenceResponse = $client->sendRequest($evidenceRequest);
$contract->validateExchange($evidenceRequest, $evidenceResponse)->assertValid();
$evidence = json_decode((string) $evidenceResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
$outboxTypes = array_column((array) $evidence['outbox'], 'type');
if ($evidence['order']['id'] !== $orderId || !in_array('payment.requested', $outboxTypes, true) || $evidence['audit'] === []) {
    throw new RuntimeException('Evidence of an accepted order must hold its payment.requested row and an audit entry');
}
foreach ([
    '/api/v1/workflows/order', '/api/v1/resilience/payment-gateway', '/api/v1/operator', '/api/v1/webhooks',
    '/api/v1/analytics', '/api/v1/contract/checkout-cases?seed=11', '/api/v1/agent/transcript?orderId=' . rawurlencode($orderId),
    '/api/v1/orders/' . rawurlencode($orderId) . '/realtime-token', '/api/v1/orders/' . rawurlencode($orderId) . '/trace',
] as $readOnlyPath) {
    $readOnlyRequest = new Request('GET', $baseUrl . $readOnlyPath, ['Accept' => 'application/json']);
    $readOnlyResponse = $client->sendRequest($readOnlyRequest);
    $contract->validateExchange($readOnlyRequest, $readOnlyResponse)->assertValid();
    if ($readOnlyResponse->getStatusCode() !== 200) {
        throw new RuntimeException("Expected {$readOnlyPath} HTTP 200, got " . $readOnlyResponse->getStatusCode());
    }
}
$agentBody = json_decode((string) $client->sendRequest(new Request('GET', $baseUrl . '/api/v1/agent/transcript?orderId=' . rawurlencode($orderId)))->getBody(), true, 512, JSON_THROW_ON_ERROR);
[$permitted, $limitedAgent] = $agentBody['identities'];
if (count($permitted['steps'][0]['result']) !== 3 || array_filter($permitted['steps'], static fn (array $step): bool => $step['refused']) !== []
    || $limitedAgent['steps'][0]['result'] !== [] || array_filter(array_slice($limitedAgent['steps'], 1), static fn (array $step): bool => !$step['refused']) !== []) {
    throw new RuntimeException('The permitted agent must use all three tools and the limited one none: ' . json_encode($agentBody, JSON_THROW_ON_ERROR));
}
$casesBody = json_decode((string) $client->sendRequest(new Request('GET', $baseUrl . '/api/v1/contract/checkout-cases?seed=11'))->getBody(), true, 512, JSON_THROW_ON_ERROR);
$caseKinds = array_count_values(array_column($casesBody['cases'], 'kind'));
if (($caseKinds['valid'] ?? 0) === 0 || ($caseKinds['invalid'] ?? 0) === 0) {
    throw new RuntimeException('The contract page must get valid and invalid generated cases, got ' . json_encode($caseKinds, JSON_THROW_ON_ERROR));
}
foreach ($casesBody['cases'] as $generated) {
    if (in_array(json_decode($generated['body'], true)['amountCents'] ?? null, [4002, 5030, 5031], true)) {
        throw new RuntimeException('A generated case must never carry a payment-mock test amount');
    }
}
$operatorBody = (string) $client->sendRequest(new Request('GET', $baseUrl . '/api/v1/operator'))->getBody();
if (str_contains($operatorBody, 'bypass') || str_contains($operatorBody, 'sandbox-centrifugo-key') || str_contains($operatorBody, 'sandbox-mcp-secret')) {
    throw new RuntimeException('The operator view must never carry a secret or the maintenance bypass');
}
if (!in_array('mark_paid', (array) $evidence['nextTransitions'], true) || ((array) $evidence['audit'][0]['changes'])[0]['field'] !== 'status') {
    throw new RuntimeException('Evidence of a processing order must offer mark_paid and carry its audit change set');
}

foreach ([['acme', 200], ['no-such-tenant', 404], [null, 404]] as [$tenantId, $expectedTenantStatus]) {
    $tenantRequest = new Request('GET', $baseUrl . '/api/v1/tenant', $tenantId === null ? [] : ['X-Tenant-Id' => $tenantId]);
    $tenantResponse = $client->sendRequest($tenantRequest);
    $contract->validateExchange($tenantRequest, $tenantResponse)->assertValid();
    if ($tenantResponse->getStatusCode() !== $expectedTenantStatus) {
        throw new RuntimeException('Expected tenant ' . ($tenantId ?? '(none)') . " HTTP {$expectedTenantStatus}, got " . $tenantResponse->getStatusCode());
    }
}

$missingEvidenceRequest = new Request('GET', $baseUrl . '/api/v1/orders/contract-missing-' . time() . '/evidence');
$missingEvidenceResponse = $client->sendRequest($missingEvidenceRequest);
$contract->validateExchange($missingEvidenceRequest, $missingEvidenceResponse)->assertValid();
if ($missingEvidenceResponse->getStatusCode() !== 404) {
    throw new RuntimeException('Expected missing evidence HTTP 404');
}

$missingTimelineRequest = new Request('GET', $baseUrl . '/api/v1/orders/contract-missing-' . time() . '/timeline');
$missingTimelineResponse = $client->sendRequest($missingTimelineRequest);
$contract->validateExchange($missingTimelineRequest, $missingTimelineResponse)->assertValid();
if ($missingTimelineResponse->getStatusCode() !== 404) {
    throw new RuntimeException('Expected missing timeline HTTP 404');
}

// A cheaper order in the same status makes the amount filter observable, and
// the status filter is checked against a status nothing here has reached.
$cheapOrderId = 'contract-cheap-' . time();
$client->sendRequest(new Request(
    method: 'POST',
    uri: $baseUrl . '/api/v1/checkout',
    headers: ['Content-Type' => 'application/json'],
    body: json_encode(['orderId' => $cheapOrderId, 'customerId' => 'contract-customer', 'amountCents' => 1], JSON_THROW_ON_ERROR),
));

$listRequest = new Request('GET', $baseUrl . '/api/v1/orders?status=processing&minAmountCents=3210&limit=100');
$listResponse = $client->sendRequest($listRequest);
$contract->validateExchange($listRequest, $listResponse)->assertValid();
$listItems = (array) json_decode((string) $listResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['items'];
$listedIds = array_column($listItems, 'id');
foreach ($listItems as $item) {
    if ($item['status'] !== 'processing' || $item['amountCents'] < 3210) {
        throw new RuntimeException('Order list ignored its filters: ' . json_encode($item, JSON_THROW_ON_ERROR));
    }
}
if (!in_array($orderId, $listedIds, true) || in_array($cheapOrderId, $listedIds, true)) {
    throw new RuntimeException('Order list must contain the matching order and exclude the cheaper one');
}

$malformedRequest = new Request(
    method: 'POST',
    uri: $baseUrl . '/api/v1/checkout',
    headers: ['Content-Type' => 'application/json'],
    body: '{bad',
);
$malformedResponse = $client->sendRequest($malformedRequest);
$contract->validateResponse('checkout.create', $malformedResponse)->assertValid();
if ($malformedResponse->getStatusCode() !== 400) {
    throw new RuntimeException('Expected malformed JSON checkout HTTP 400, got ' . $malformedResponse->getStatusCode());
}

$conflictRequest = new Request(
    method: 'POST',
    uri: $baseUrl . '/api/v1/checkout',
    headers: ['Content-Type' => 'application/json', 'Idempotency-Key' => 'contract-conflict-' . $orderId],
    body: json_encode(['orderId' => $orderId, 'customerId' => 'another-customer', 'amountCents' => 999999], JSON_THROW_ON_ERROR),
);
$conflictResponse = $client->sendRequest($conflictRequest);
$contract->validateExchange($conflictRequest, $conflictResponse)->assertValid();
if ($conflictResponse->getStatusCode() !== 409) {
    throw new RuntimeException('Expected a different payload for an existing order to be HTTP 409, got ' . $conflictResponse->getStatusCode());
}

// The schema allows any 1-64 character id; the path carries it percent-encoded.
$spacedOrderId = 'contract order ' . time();
$client->sendRequest(new Request(
    method: 'POST',
    uri: $baseUrl . '/api/v1/checkout',
    headers: ['Content-Type' => 'application/json'],
    body: json_encode(['orderId' => $spacedOrderId, 'customerId' => 'contract-customer', 'amountCents' => 10], JSON_THROW_ON_ERROR),
));
foreach (['', '/timeline', '/evidence'] as $suffix) {
    $spacedRequest = new Request('GET', $baseUrl . '/api/v1/orders/' . rawurlencode($spacedOrderId) . $suffix);
    $spacedResponse = $client->sendRequest($spacedRequest);
    $contract->validateExchange($spacedRequest, $spacedResponse)->assertValid();
    $spacedBody = json_decode((string) $spacedResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    if ($spacedResponse->getStatusCode() !== 200 || ($spacedBody['id'] ?? $spacedBody['orderId'] ?? $spacedBody['order']['id'] ?? null) !== $spacedOrderId) {
        throw new RuntimeException('An encoded order id must resolve on /orders/{id}' . $suffix);
    }
}

$invalidRequest = new Request(
    method: 'POST',
    uri: $baseUrl . '/api/v1/checkout',
    headers: ['Content-Type' => 'application/json'],
    body: json_encode([
        'orderId' => 'invalid-' . $orderId,
        'customerId' => 'contract-customer',
        'amountCents' => 0,
    ], JSON_THROW_ON_ERROR),
);
$invalidRequestResult = $contract->validateRequest($invalidRequest);
if ($invalidRequestResult->isValid()) {
    throw new RuntimeException('Contract accepted an invalid amount');
}

$invalidResponse = $client->sendRequest($invalidRequest);
$contract->validateResponse('checkout.create', $invalidResponse)->assertValid();
if ($invalidResponse->getStatusCode() !== 400) {
    throw new RuntimeException('Expected invalid checkout HTTP 400');
}

$missingRequest = new Request('GET', $baseUrl . '/api/v1/orders/contract-missing-' . time());
$missingResponse = $client->sendRequest($missingRequest);
$contract->validateExchange($missingRequest, $missingResponse)->assertValid();
if ($missingResponse->getStatusCode() !== 404) {
    throw new RuntimeException('Expected missing order HTTP 404');
}

// The tests page's backend: whatever build/ holds (nothing on a fresh
// checkout, full reports after make testo / mutation), the answer matches
// the document.
$testsRequest = new Request('GET', $baseUrl . '/api/v1/ui/tests');
$testsResponse = $client->sendRequest($testsRequest);
$contract->validateExchange($testsRequest, $testsResponse)->assertValid();
$testsReport = json_decode((string) $testsResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
$testsParts = implode('+', array_keys(array_filter($testsReport, static fn (mixed $part): bool => $part !== null))) ?: 'none';

printf(
    "OPENAPI CONTRACT PASS: checkout=%d order=%d timeline=%d(events=%d) list=%d(items=%d) malformed=%d conflict=%d invalid=%d missing=%d missing-timeline=%d tests=%d(%s)\n",
    $checkoutResponse->getStatusCode(),
    $orderResponse->getStatusCode(),
    $timelineResponse->getStatusCode(),
    count((array) $timeline['events']),
    $listResponse->getStatusCode(),
    count($listItems),
    $malformedResponse->getStatusCode(),
    $conflictResponse->getStatusCode(),
    $invalidResponse->getStatusCode(),
    $missingResponse->getStatusCode(),
    $missingTimelineResponse->getStatusCode(),
    $testsResponse->getStatusCode(),
    $testsParts,
);
