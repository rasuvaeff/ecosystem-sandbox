#!/usr/bin/env bash
set -euo pipefail

# The opt-in captcha gate (yii3-recaptcha) on the checkout, verified against
# the payment mock's stand-in for Google's siteverify: no internet needed.
compose="${COMPOSE:-docker compose}"
base_url="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
verify_url='http://payment-mock:8081/recaptcha/siteverify'

restore() {
  CAPTCHA_ENABLED=false $compose up -d --force-recreate --no-deps --wait app >/dev/null
}
trap restore EXIT

# The whole app first (database, Redis, the mocks): the gate is then switched
# on by recreating the app alone.
$compose up -d --build --wait app payment-mock >/dev/null
CAPTCHA_ENABLED=true RECAPTCHA_VERIFY_URL="$verify_url" $compose up -d --force-recreate --no-deps --wait app >/dev/null
$compose exec -T app ./yii migrate:up -n >/dev/null

config=$(curl -fsS "$base_url/api/v1/captcha")
grep -q '"enabled":true' <<<"$config" || { echo "the captcha endpoint must report the gate on: $config" >&2; exit 1; }
grep -q '"siteKey":"' <<<"$config" || { echo "the captcha endpoint must hand out the site key: $config" >&2; exit 1; }
if grep -qi secret <<<"$config"; then echo "the captcha endpoint leaked the secret: $config" >&2; exit 1; fi

checkout() {
  local order_id="$1"; shift
  curl -sS -o /tmp/sandbox-captcha.json -w '%{http_code}' -X POST "$base_url/api/v1/checkout" \
    -H 'Content-Type: application/json' "$@" \
    --data "$(printf '{"orderId":"%s","customerId":"captcha-customer","amountCents":1999}' "$order_id")"
}

run="captcha-$(date +%s)"
missing=$(checkout "$run-missing")
[[ "$missing" == 403 ]] && grep -q 'captcha-failed' /tmp/sandbox-captcha.json || { echo "no token must be a 403 captcha-failed, got $missing" >&2; exit 1; }
wrong=$(checkout "$run-wrong" -H 'X-Captcha-Token: forged')
[[ "$wrong" == 403 ]] || { echo "a token that does not verify must be a 403, got $wrong" >&2; exit 1; }
passed=$(checkout "$run-pass" -H 'X-Captcha-Token: test-captcha-pass')
[[ "$passed" == 202 ]] || { cat /tmp/sandbox-captcha.json; echo "a verified token must reach the checkout, got $passed" >&2; exit 1; }
order=$(curl -sS -o /dev/null -w '%{http_code}' "$base_url/api/v1/orders/$run-missing")
[[ "$order" == 404 ]] || { echo "a refused checkout must create no order, got $order" >&2; exit 1; }

echo "CAPTCHA PASS: no token=403, forged=403, verified=202, refused checkout left no order, site key public, secret kept"
