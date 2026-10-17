#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "$0")/support/require-no-ui-worker.sh"

compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
prometheus_url="${PROMETHEUS_URL:-http://127.0.0.1:${PROMETHEUS_PORT:-9090}}"
suffix="$(date +%s)"

$compose up -d --build --wait app prometheus >/dev/null
$compose exec -T app ./yii migrate:up -n >/dev/null

checkout() {
    local order_id="$1"
    curl -fsS -X POST "$base_url/api/v1/checkout" \
        -H 'Content-Type: application/json' \
        -H "Idempotency-Key: outcome-$order_id" \
        --data "{\"orderId\":\"$order_id\",\"customerId\":\"outcome\",\"amountCents\":1000}" >/dev/null
}

PAYMENT_MOCK_FAIL=0 PAYMENT_MOCK_UNAVAILABLE=0 PAYMENT_MOCK_DELAY_MS=0 \
    $compose up -d --force-recreate --no-deps payment-mock >/dev/null
checkout "outcome-paid-$suffix"
$compose exec -T app ./yii sandbox:outbox:run >/dev/null

PAYMENT_MOCK_FAIL=1 PAYMENT_MOCK_UNAVAILABLE=0 PAYMENT_MOCK_DELAY_MS=0 \
    $compose up -d --force-recreate --no-deps payment-mock >/dev/null
checkout "outcome-failed-$suffix"
$compose exec -T app ./yii sandbox:outbox:run >/dev/null

PAYMENT_MOCK_FAIL=0 PAYMENT_MOCK_UNAVAILABLE=1 PAYMENT_MOCK_DELAY_MS=0 \
    $compose up -d --force-recreate --no-deps payment-mock >/dev/null
checkout "outcome-rejected-$suffix"
$compose exec -T app ./yii sandbox:outbox:run >/dev/null

PAYMENT_MOCK_FAIL=0 PAYMENT_MOCK_UNAVAILABLE=0 PAYMENT_MOCK_DELAY_MS=0 \
    $compose up -d --force-recreate --no-deps payment-mock >/dev/null

sleep 2
metrics="$(curl -fsS "$base_url/metrics")"
for outcome in paid failed rejected; do
    grep -Eq "sandbox_sandbox_payment_outcomes_total\\{outcome=\"$outcome\"\\} [1-9]" <<<"$metrics" \
        || { echo "missing payment outcome metric: $outcome" >&2; exit 1; }
done

query() {
    curl -fsS "$prometheus_url/api/v1/query?query=$(printf '%s' "$1" | jq -sRr @uri)" \
        | jq -er '.data.result[0].value[1]'
}

paid="$(query 'sandbox_sandbox_payment_outcomes_total{outcome="paid"}')"
failed="$(query 'sandbox_sandbox_payment_outcomes_total{outcome="failed"}')"
rejected="$(query 'sandbox_sandbox_payment_outcomes_total{outcome="rejected"}')"
awk -v paid="$paid" -v failed="$failed" -v rejected="$rejected" 'BEGIN { exit !(paid > 0 && failed > 0 && rejected > 0) }'
echo "PROMETHEUS OUTCOMES PASS: paid=$paid failed=$failed rejected=$rejected"
