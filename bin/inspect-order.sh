#!/usr/bin/env bash
set -euo pipefail

# Database evidence for one order: the row itself, its workflow transitions,
# audit entries and outbox messages.

compose="${COMPOSE:-docker compose}"
order_id="${1:?usage: inspect-order.sh <order id>}"

psql() { $compose exec -T postgres psql -U sandbox -d sandbox -c "$1"; }

echo "==> order $order_id"
psql "SELECT id, customer_id, amount_cents, status, created_at, updated_at FROM orders WHERE id = '$order_id'"
echo '==> workflow transitions'
psql "SELECT transition, from_place, to_place, at, idempotency_key FROM workflow_transitions WHERE subject_id = '$order_id' ORDER BY at, id"
echo '==> audit log'
psql "SELECT action, subject_type, request_id, occurred_at FROM audit_log WHERE subject_id = '$order_id' ORDER BY occurred_at"
echo '==> outbox'
psql "SELECT id, type, status, attempts, created_at FROM outbox WHERE aggregate_id = '$order_id' ORDER BY created_at"
