<?php

declare(strict_types=1);

$baseUrl = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$request = @file_get_contents($baseUrl . '/api/v1/orders?limit=1');
if ($request === false) {
    throw new RuntimeException('Sandbox HTTP endpoint is unavailable');
}
$metrics = file_get_contents($baseUrl . '/metrics');
if ($metrics === false || !str_contains($metrics, 'sandbox_http_server')) {
    throw new RuntimeException('Prometheus exposition did not contain HTTP metrics');
}
$prometheusUrl = getenv('PROMETHEUS_URL') ?: 'http://prometheus:9090';
$query = static function (string $expression) use ($prometheusUrl): float {
    $url = $prometheusUrl . '/api/v1/query?query=' . rawurlencode($expression);
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        $body = @file_get_contents($url);
        if ($body === false) {
            throw new RuntimeException('Prometheus query API is unavailable');
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $value = $decoded['data']['result'][0]['value'][1] ?? null;
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (float) $value;
        }

        // Prometheus scrapes the app asynchronously; allow the first scrape
        // to publish counters created by the smoke request.
        usleep(500_000);
    }

    throw new RuntimeException('Prometheus query returned no series: ' . $expression);
};
$paid = $query('sandbox_sandbox_payment_outcomes_total{outcome="paid"}');
if ($paid < 1) {
    throw new RuntimeException('Prometheus did not record a paid business outcome');
}
$jaegerUrl = getenv('JAEGER_URL') ?: 'http://jaeger:16686';
$body = @file_get_contents($jaegerUrl . '/api/services');
if ($body === false) {
    throw new RuntimeException('Jaeger query API is unavailable');
}
$services = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($services) || !in_array('rasuvaeff-sandbox', $services['data'] ?? [], true)) {
    throw new RuntimeException('Jaeger has not registered rasuvaeff-sandbox yet');
}
$tracesBody = @file_get_contents($jaegerUrl . '/api/traces?service=rasuvaeff-sandbox&limit=20');
if ($tracesBody === false) {
    throw new RuntimeException('Jaeger trace query API is unavailable');
}
$traces = json_decode($tracesBody, true, 512, JSON_THROW_ON_ERROR);
$serverSpan = false;
$consoleSpan = false;
$consumerSpan = false;
$sensitiveAttribute = false;
foreach ($traces['data'] ?? [] as $trace) {
    foreach ($trace['spans'] ?? [] as $span) {
        if (($span['operationName'] ?? '') === 'console sandbox:outbox:run') {
            $consoleSpan = true;
        }
        if (($span['operationName'] ?? '') === 'process outbox.payment') {
            $consumerSpan = true;
        }
        foreach ($span['tags'] ?? [] as $tag) {
            if (($tag['key'] ?? '') === 'span.kind' && ($tag['value'] ?? '') === 'server') {
                $serverSpan = true;
            }
            if (str_contains((string) ($tag['key'] ?? ''), 'body') || str_contains((string) ($tag['key'] ?? ''), 'token')) {
                $sensitiveAttribute = true;
            }
        }
    }
}
if (!$serverSpan || !$consoleSpan || !$consumerSpan || $sensitiveAttribute) {
    throw new RuntimeException('Jaeger server span or sensitive attribute assertion failed');
}
echo "OBSERVABILITY PASS: prometheus=http_metrics paid={$paid} jaeger=server_span+worker_span sensitive_attrs=absent\n";
