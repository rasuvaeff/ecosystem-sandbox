#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "$0")/support/require-no-ui-worker.sh"

compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
suffix="$(date +%s)"

echo '==> starting sandbox infrastructure'
$compose up -d --build --wait app >/dev/null
$compose exec -T app ./yii migrate:up -n >/dev/null

# The cooldown comes from the running breaker's own config, plus a second of
# slack, so this script cannot drift from config/common/di/app.php.
breaker_cooldown=$(curl -fsS "$base_url/api/v1/resilience/payment-gateway" \
    | grep -o '"cooldownSeconds":[0-9]*' | cut -d: -f2)
breaker_cooldown=$((breaker_cooldown + 1))

echo '==> creating three payment requests'
for index in 1 2 3; do
    order_id="collapse-${suffix}-${index}"
    body=$(printf '{"orderId":"%s","customerId":"customer-collapse","amountCents":%d}' "$order_id" "$((1000 + index))")
    curl -fsS -X POST "$base_url/api/v1/checkout" \
        -H 'Content-Type: application/json' \
        -H "Idempotency-Key: collapse-$order_id" \
        --data "$body"
    printf '\n'
done

echo '==> injecting a five-second payment gateway delay'
PAYMENT_MOCK_DELAY_MS=5000 PAYMENT_MOCK_UNAVAILABLE=0 $compose up -d --force-recreate --no-deps payment-mock >/dev/null
$compose exec -T app ./yii sandbox:outbox:run

echo '==> restoring the payment gateway'
PAYMENT_MOCK_DELAY_MS=0 PAYMENT_MOCK_UNAVAILABLE=0 $compose up -d --force-recreate --no-deps payment-mock >/dev/null

# The timeouts above opened the circuit breaker. Until its cooldown ends every
# call is rejected without reaching the gateway, so recovery is only observable
# after it — retrying at once just spends an outbox attempt on an open circuit.
echo "==> waiting ${breaker_cooldown}s for the circuit breaker cooldown, then retrying the pending outbox messages"
sleep "$breaker_cooldown"
$compose exec -T app ./yii sandbox:outbox:run

echo '==> final order states'
for index in 1 2 3; do
    state=$(curl -fsS "$base_url/api/v1/orders/collapse-${suffix}-${index}")
    printf '%s\n' "$state"
    grep -q '"status":"paid"' <<<"$state" || { echo "collapse-${suffix}-${index} did not recover to paid" >&2; exit 1; }
done

echo '==> taking the gateway down until the outbox gives up on a fourth order'
order_id="collapse-${suffix}-exhausted"
body=$(printf '{"orderId":"%s","customerId":"customer-collapse","amountCents":1004}' "$order_id")
curl -fsS -X POST "$base_url/api/v1/checkout" -H 'Content-Type: application/json' --data "$body" >/dev/null
PAYMENT_MOCK_DELAY_MS=0 PAYMENT_MOCK_UNAVAILABLE=1 $compose up -d --force-recreate --no-deps payment-mock >/dev/null
for attempt in 1 2 3; do
    $compose exec -T app ./yii sandbox:outbox:run
done
PAYMENT_MOCK_DELAY_MS=0 PAYMENT_MOCK_UNAVAILABLE=0 $compose up -d --force-recreate --no-deps payment-mock >/dev/null

state=$(curl -fsS "$base_url/api/v1/orders/$order_id")
printf '%s\n' "$state"
grep -q '"status":"payment_failed"' <<<"$state" || { echo "$order_id stayed out of payment_failed after the last attempt" >&2; exit 1; }
outbox=$($compose exec -T postgres psql -qAt -U sandbox -d sandbox -c \
    "SELECT type || ':' || status FROM outbox WHERE aggregate_id = '$order_id' ORDER BY created_at")
printf '%s\n' "$outbox"
grep -qx 'payment.requested:failed' <<<"$outbox" || { echo 'the exhausted payment.requested row must be failed' >&2; exit 1; }
grep -qx 'order.payment_failed:pending' <<<"$outbox" || { echo 'the abandoned order must record order.payment_failed' >&2; exit 1; }

# Leave a closed breaker behind, so the next target does not start against an
# open circuit it did not cause.
sleep "$breaker_cooldown"

echo 'NETWORK COLLAPSE PASS: timeout, retries, circuit breaker, recovery to paid, and payment_failed once the outbox gives up'
