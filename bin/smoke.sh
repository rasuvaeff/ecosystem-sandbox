#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "$0")/support/require-no-ui-worker.sh"

base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
order_id="smoke-$(date +%s)"
body=$(printf '{"orderId":"%s","customerId":"customer-1","amountCents":1999}' "$order_id")
idempotency_key="smoke-$order_id"

echo '==> liveness/readiness'
live=$(curl -fsS "$base_url/live")
ready=$(curl -fsS "$base_url/ready")
grep -q '"status":"pass"' <<<"$live"
grep -q '"database"' <<<"$ready"
grep -q '"payment-mock"' <<<"$ready"

echo '==> valid checkout'
response=$(curl -fsS -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $idempotency_key" \
  --data "$body")
echo "$response"
grep -q '"status":"processing"' <<<"$response"

echo '==> idempotent replay'
replay=$(curl -fsS -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $idempotency_key" \
  --data "$body")
grep -q '"orderId":"' <<<"$replay"

echo '==> persisted order'
order=$(curl -fsS "$base_url/api/v1/orders/$order_id")
echo "$order"
grep -q '"status":"processing"' <<<"$order"

echo '==> outbox worker'
docker compose exec -T app ./yii sandbox:outbox:run >/dev/null
paid=$(curl -fsS "$base_url/api/v1/orders/$order_id")
echo "$paid"
grep -q '"status":"paid"' <<<"$paid"

echo 'SMOKE PASS: checkout, workflow, audit, outbox and payment worker path completed'
