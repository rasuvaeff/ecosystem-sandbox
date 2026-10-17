#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "$0")/support/require-no-ui-worker.sh"

compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
order_id="race-$(date +%s)"
requests="${RACE_REQUESTS:-50}"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

echo '==> starting sandbox infrastructure'
$compose up -d --build --wait app >/dev/null
$compose exec -T app ./yii migrate:up -n >/dev/null

echo "==> sending $requests concurrent checkouts for order $order_id"
export base_url order_id tmp_dir
seq "$requests" | xargs -P "$requests" -I '{}' bash -c '
    index="$1"
    body=$(printf '\''{"orderId":"%s","customerId":"race-customer","amountCents":4242}'\'' "$order_id")
    curl -sS -o "$tmp_dir/response-$index" -w "%{http_code}" \
        -X POST "$base_url/api/v1/checkout" \
        -H '\''Content-Type: application/json'\'' \
        -H "Idempotency-Key: race-$order_id-$index" \
        --data "$body" > "$tmp_dir/status-$index" || true
' _ '{}'

status_count=$(for file in "$tmp_dir"/status-*; do cat "$file"; printf '\n'; done | sort | uniq -c | sed 's/^ *//')
echo "HTTP statuses: $status_count"

if grep -qv '^202$' "$tmp_dir"/status-*; then
    echo 'Race simulation failed: at least one request did not return HTTP 202' >&2
    exit 1
fi

order=$(curl -fsS "$base_url/api/v1/orders/$order_id")
echo "Final order: $order"
grep -q '"status":"processing"' <<< "$order"

accepted_true=$(for file in "$tmp_dir"/response-*; do jq -r 'select(.accepted == true) | .accepted' "$file"; done | grep -c '^true$' || true)
accepted_false=$(for file in "$tmp_dir"/response-*; do jq -r 'select(.accepted == false) | .accepted' "$file"; done | grep -c '^false$' || true)
echo "Accepted responses: true=$accepted_true false=$accepted_false"

if [[ "$accepted_true" -ne 1 || "$accepted_false" -ne $((requests - 1)) ]]; then
    echo "Race simulation failed: expected one accepted request and $((requests - 1)) rejected replays" >&2
    exit 1
fi

counts=$($compose exec -T postgres psql -U sandbox -d sandbox -At <<SQL
SELECT
  (SELECT count(*) FROM workflow_transitions WHERE workflow = 'order' AND subject_id = '$order_id' AND transition = 'start_payment'),
  (SELECT count(*) FROM audit_log WHERE subject_type = 'order' AND subject_id = '$order_id'),
  (SELECT count(*) FROM outbox WHERE aggregate_id = '$order_id' AND type = 'payment.requested');
SQL
)
echo "workflow/audit/outbox counts: $counts"

expected='1|1|1'
if [[ "$counts" != "$expected" ]]; then
    echo "Race simulation failed: expected $expected" >&2
    exit 1
fi

echo "RACE CONDITION PASS: $requests requests converged on one locked transition and one outbox event"
