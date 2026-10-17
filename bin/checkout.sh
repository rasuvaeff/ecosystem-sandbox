#!/usr/bin/env bash
set -euo pipefail

# One checkout against the running stack; REPLAY=1 repeats it under the same
# Idempotency-Key and shows that the second call creates nothing new.

base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
compose="${COMPOSE:-docker compose}"
order_id="${ORDER_ID:-checkout-$(date +%s)}"
key="checkout-$order_id"
body=$(printf '{"orderId":"%s","customerId":"%s","amountCents":%d}' "$order_id" "${CUSTOMER_ID:-customer-1}" "${AMOUNT_CENTS:-1999}")

post() {
    curl -sS -D - -o /tmp/checkout-body.json -X POST "$base_url/api/v1/checkout" \
      -H 'Content-Type: application/json' -H "Idempotency-Key: $key" --data "$body"
}

echo "==> POST /api/v1/checkout ($order_id)"
headers=$(post)
status=$(head -n1 <<<"$headers" | awk '{print $2}')
echo "    status=$status request-id=$(grep -i '^x-request-id:' <<<"$headers" | tr -d '\r' | awk '{print $2}')"
cat /tmp/checkout-body.json; echo

if [[ "${REPLAY:-0}" == '1' ]]; then
    echo '==> replaying the same Idempotency-Key'
    replay_headers=$(post)
    replay_status=$(head -n1 <<<"$replay_headers" | awk '{print $2}')
    cat /tmp/checkout-body.json; echo
    rows=$($compose exec -T postgres psql -qAt -U sandbox -d sandbox \
      -c "SELECT count(*) FROM outbox WHERE aggregate_id = '$order_id' AND type = 'payment.requested'")
    echo "    replay status=$replay_status payment.requested rows=$rows"
    if [[ "$(tr -d '[:space:]' <<<"$rows")" != '1' ]]; then
        echo 'FAIL: a replay created a second payment request' >&2
        exit 1
    fi
    echo 'CHECKOUT REPLAY PASS: one business transition, one payment event'
fi
