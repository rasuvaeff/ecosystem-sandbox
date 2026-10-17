#!/usr/bin/env bash
set -euo pipefail

# Maintenance scenario of the plan: controlled 503 with Retry-After and a
# correlation id, health endpoints excluded, header bypass, no bypass token in
# the logs, and no 503 replayed under a caller's idempotency key once
# maintenance is over.
#
# What that last step does and does not prove: yii3-idempotency caches only 2xx
# handler responses, so a 503 releases the claim whatever the middleware order
# is - this step pins that package contract, not our ordering. Maintenance is
# still placed before idempotency on purpose (no claim is written to storage at
# all while the application is down), but that is a precaution, not something
# this script can falsify.

compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
token="${MAINTENANCE_BYPASS_TOKEN:-sandbox-maintenance-bypass}"
suffix="$(date +%s)"
key="maintenance-$suffix"
body=$(printf '{"orderId":"maintenance-%s","customerId":"customer-maintenance","amountCents":1500}' "$suffix")

recreate() {
    MAINTENANCE_ENABLED="$1" MAINTENANCE_BYPASS_TOKEN="$token" \
        $compose up -d --force-recreate --no-deps --wait app >/dev/null
}

echo '==> enabling maintenance mode'
recreate true

echo '==> checkout is refused with 503 and Retry-After'
headers=$(curl -sS -D - -o /tmp/maintenance-body.json -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H "Idempotency-Key: $key" --data "$body")
status=$(head -n1 <<<"$headers" | awk '{print $2}')
retry_after=$(grep -i '^retry-after:' <<<"$headers" | tr -d '\r' | awk '{print $2}')
request_id=$(grep -i '^x-request-id:' <<<"$headers" | tr -d '\r' | awk '{print $2}')
if [[ "$status" != '503' ]]; then
    echo "FAIL: expected 503 under maintenance, got $status" >&2
    exit 1
fi
if [[ -z "$retry_after" ]]; then
    echo 'FAIL: maintenance 503 carries no Retry-After header' >&2
    exit 1
fi
# CorrelationIdMiddleware is outermost, so even a refused request is traceable.
if [[ ! "$request_id" =~ ^[0-9a-fA-F-]{36}$ ]]; then
    echo "FAIL: maintenance 503 carries no correlation id: '$request_id'" >&2
    exit 1
fi
echo "    status=$status retry-after=$retry_after request-id=$request_id"

echo '==> liveness and readiness stay out of maintenance'
curl -fsS "$base_url/live" >/dev/null
curl -fsS "$base_url/ready" >/dev/null

echo '==> bypass header reaches the application'
bypass_status=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' -H "X-Maintenance-Bypass: $token" \
  -H "Idempotency-Key: bypass-$suffix" --data "$body")
if [[ "$bypass_status" != '202' ]]; then
    echo "FAIL: bypass token did not reach checkout, got $bypass_status" >&2
    exit 1
fi

# Grep before the container is recreated: --force-recreate throws away the
# container that served the bypass request, logs included.
echo '==> the bypass token stays out of logs'
if $compose logs app 2>&1 | grep -q "$token"; then
    echo 'FAIL: the bypass token leaked into application logs' >&2
    exit 1
fi

echo '==> the refused request was not stored as an idempotent reply'
recreate false
replay_status=$(curl -sS -o /tmp/maintenance-replay.json -w '%{http_code}' -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' -H "Idempotency-Key: $key" --data "$body")
if [[ "$replay_status" != '202' ]]; then
    echo "FAIL: the maintenance 503 was replayed under the caller key, got $replay_status" >&2
    cat /tmp/maintenance-replay.json >&2
    exit 1
fi

echo "MAINTENANCE PASS: 503+Retry-After=$retry_after request-id=yes health=open bypass=202 replay-after-maintenance=$replay_status token-leak=none"
