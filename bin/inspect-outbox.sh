#!/usr/bin/env bash
set -euo pipefail

# Outbox state across all orders: what is pending, published or failed.

compose="${COMPOSE:-docker compose}"

$compose exec -T postgres psql -U sandbox -d sandbox -c \
  "SELECT type, status, count(*) AS messages, max(attempts) AS max_attempts FROM outbox GROUP BY type, status ORDER BY type, status"
$compose exec -T postgres psql -U sandbox -d sandbox -c \
  "SELECT id, type, status, attempts, claimed_at FROM outbox WHERE status <> 'published' ORDER BY created_at LIMIT 20"
