#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "$0")/support/require-no-ui-worker.sh"

# Proves the Definition of Done item "checkout and worker are linked by one
# correlation id": the id minted by CorrelationIdMiddleware for the HTTP request
# is the id both audit rows (HTTP half and worker half) are filed under.

compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
order_id="correlation-$(date +%s)"
body=$(printf '{"orderId":"%s","customerId":"customer-correlation","amountCents":2550}' "$order_id")

echo '==> checkout request'
headers=$(curl -fsS -D - -o /dev/null -X POST "$base_url/api/v1/checkout" \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: correlation-$order_id" \
  -H 'X-Request-ID: 11111111-1111-4111-8111-111111111111' \
  --data "$body")
request_id=$(grep -i '^x-request-id:' <<<"$headers" | tr -d '\r' | awk '{print $2}')
if [[ ! "$request_id" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$ ]]; then
    echo "FAIL: response carries no UUIDv4 correlation id: '$request_id'" >&2
    exit 1
fi
# acceptIncoming is false by default, which is the security default: a caller
# cannot choose the key its logs are filed under.
if [[ "$request_id" == '11111111-1111-4111-8111-111111111111' ]]; then
    echo 'FAIL: the caller-supplied correlation id was trusted' >&2
    exit 1
fi
echo "    correlation id: $request_id"

echo '==> payment worker'
$compose exec -T app ./yii sandbox:outbox:run >/dev/null

echo '==> audit rows of both halves'
query="SELECT request_id, action FROM audit_log WHERE subject_id = '$order_id' ORDER BY occurred_at"
rows=$($compose exec -T postgres psql -qAt -U sandbox -d sandbox -c "$query")
echo "$rows" | sed 's/^/    /'
total=$(grep -c . <<<"$rows")
matching=$(grep -c "^$request_id|" <<<"$rows" || true)
if [[ "$total" -lt 2 ]]; then
    echo "FAIL: expected an audit row for the HTTP half and for the worker half, got $total" >&2
    exit 1
fi
if [[ "$matching" != "$total" ]]; then
    echo "FAIL: only $matching of $total audit rows carry the request correlation id" >&2
    exit 1
fi

echo "CORRELATION PASS: id=$request_id audit_rows=$total linked=$matching"
