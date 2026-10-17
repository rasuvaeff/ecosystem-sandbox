#!/usr/bin/env bash
set -euo pipefail

compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"

# The app must be up and migrated: the flag row is seeded by the flag data migration (migrate:up).
$compose up -d --wait app >/dev/null
$compose exec -T app ./yii migrate:up -n >/dev/null

# The flag lives in the feature_flags table (yii3-feature-flags-db) and is
# flipped through the package's own admin form (yii3-feature-flags-ui): the
# same POST an operator's browser sends. The checkout reads that table on the
# next request — nothing is restarted.
set_payments() {
  local fields=(--data-urlencode 'Flag[rollout]=100' --data-urlencode 'Flag[salt]=payments.enabled' --data-urlencode 'Flag[environments]=[]')
  if [[ "$1" == on ]]; then
    fields+=(--data-urlencode 'Flag[enabled]=1')
  fi
  local status
  status=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$base_url/admin/flags/payments.enabled" "${fields[@]}")
  if [[ "$status" != 302 ]]; then
    echo "The admin form did not save the flag (HTTP $status)" >&2
    exit 1
  fi
}
restore_flag() {
  set_payments on
}
trap restore_flag EXIT

echo '==> disabling payments through the admin page'
set_payments off

live=$(curl -fsS "$base_url/live")
grep -q '"status":"pass"' <<<"$live"

order_id="kill-switch-$(date +%s)"
body=$(printf '{"orderId":"%s","customerId":"customer-1","amountCents":1999}' "$order_id")
status=$(curl -sS -o /tmp/sandbox-kill-switch-response.json -w '%{http_code}' \
  -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' \
  --data "$body")

if [[ "$status" != 503 ]]; then
  cat /tmp/sandbox-kill-switch-response.json
  echo "Expected checkout HTTP 503 while payments are disabled, received $status" >&2
  exit 1
fi
grep -q '"status":503' /tmp/sandbox-kill-switch-response.json

# The kill switch answers from inside CheckoutAction, which sits BEHIND
# IdempotencyMiddleware. yii3-idempotency caches only 2xx handler responses, so
# the refused call must stay retryable: after the flag is back, the same key has
# to reach the handler instead of replaying the stored 503. This pins the
# package contract the sandbox relies on.
key="kill-switch-$order_id"
refused=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' -H "Idempotency-Key: $key" --data "$body")
if [[ "$refused" != 503 ]]; then
  echo "Expected 503 for the keyed request while payments are disabled, received $refused" >&2
  exit 1
fi

set_payments on
replay=$(curl -sS -o /tmp/sandbox-kill-switch-replay.json -w '%{http_code}' -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' -H "Idempotency-Key: $key" --data "$body")
if [[ "$replay" != 202 ]]; then
  cat /tmp/sandbox-kill-switch-replay.json
  echo "Expected 202 after the flag was restored, received $replay (a 503 was replayed)" >&2
  exit 1
fi

echo "KILL SWITCH PASS: admin page turned checkout into 503, liveness healthy, same key accepted ($replay) after the flag was turned back on"
