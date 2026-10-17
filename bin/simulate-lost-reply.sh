#!/usr/bin/env bash
set -euo pipefail

# The payment saga's recovery: the bank takes the money and the answer is
# lost (test amount 5040). The worker gives up, the order fails, and the
# reconciliation finds the charge and pays the order after all. Effects are
# checked where they happen: the bank's ledger, the order, the saga, the
# partner.
source "$(dirname "$0")/support/require-no-ui-worker.sh"

compose="${COMPOSE:-docker compose}"
$compose up -d --build --wait app >/dev/null
$compose exec -T app ./yii migrate:up -n >/dev/null
$compose exec -T -e RESULT_SINKS=webhooks app php bin/simulate-lost-reply.php
