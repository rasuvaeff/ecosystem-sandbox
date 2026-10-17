# Ecosystem Sandbox

[![Build](https://github.com/rasuvaeff/ecosystem-sandbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/ecosystem-sandbox/actions/workflows/build.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/ecosystem-sandbox/actions/workflows/build.yml)
[![PHP](https://img.shields.io/badge/php-8.5-777BB4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

A runnable checkout application that wires independent `rasuvaeff/*` packages
together with Yii3 configuration, PSR interfaces and its own business code.
It is not a Composer package and not a framework: every integration point is
explicit application code you can read.

This document and [README.ru.md](README.ru.md) are two versions of one guide —
a change here belongs in both.

## Quick start

```bash
git clone https://github.com/rasuvaeff/ecosystem-sandbox.git
cd ecosystem-sandbox
make smoke
```

`make smoke` builds the images, starts PostgreSQL, Redis, Jaeger, Prometheus,
the payment mock and the webhook mock, migrates the database, drives a checkout
through the HTTP API and the worker, and tears the stack down again.

The `rasuvaeff/*` packages are installed from **Packagist**, at their released
versions, the way any application would get them. The sandbox needs nothing
outside its own directory: Compose builds and mounts this checkout only. Docker
with Compose v2 and `make` are all the host needs.

The image runs **PHP 8.5**, pinned on purpose (`"php": "~8.5.0"` and
`config.platform.php` in `composer.json`, `php:8.5-cli-alpine`): the sandbox
shows the packages on the newest PHP they support, and `make check` (php-cs-fixer,
psalm level 1 without suppressions, deptrac, rector) holds the code to it.

The application's own code is an example too: DDD layers cut into vertical
slices per context, commands and queries with one handler each, contexts
talking through domain and integration events, no ActiveRecord and no service
locator — see [docs/architecture.md](docs/architecture.md). The database
schema is the stock tools': `./yii migrate:up -n` (yiisoft/db-migration, every
package's migrations plus the application's) and `./yii
clickhouse:migrations:migrate` (yii3-clickhouse-toolkit). A volume created
before that switch has tables without a migration history: run `make down`
once.

## Web UI

```bash
make ui        # then open http://127.0.0.1:8080
make ui-full   # the same plus ClickHouse analytics and Grafana
make ui-down
```

Pages for someone who is not a developer, each explaining in plain words what
a part of the system does and why, in Russian and English. Every number on
them is read from the running system — the database, Redis, Jaeger, Centrifugo,
the webhook receiver — and nothing is animated on a timer. Each page is
rendered on the server with its own head by `rasuvaeff/yii3-seo` (title,
description, canonical URL, Open Graph, JSON-LD; the personal order page is
`noindex`), and `/robots.txt` keeps an undeployed sandbox out of search engines.

| Page | What it shows | Packages behind it |
|---|---|---|
| Paying for an order | place an order with a test card and watch it step by step: the workflow graph (path taken, transitions possible now), the audit log, a live circuit breaker strip, a status pushed over WebSocket, the replayed request; "ad" links carry a campaign that the order is attributed to; the pay button is an A/B test (control / green); a paid order gets a receipt file behind a signed link | workflow, outbox, audit-log, idempotency, centrifugo, utm, utm-db, ab-testing, ab-testing-web, filestorage, filestorage-db, filestorage-web, filestorage-attachments |
| Circuit breaker | breaker state, failure window, countdown to the probe, bulkhead slots — polled from Redis | circuit-breaker, bulkhead, resilience, retry |
| Tracing | one order's trace as a waterfall: the HTTP request, the worker's job, the bank call — one trace across the outbox; the read itself runs under a request budget (`X-Request-Timeout-Ms` shortens it, 1 ms gives a 504) | telemetry, telemetry-otel, context, context-http |
| Operator console | dependency health, feature flags with the reason, maintenance, typed settings, the real audit masker on a sample; flags and settings are changed on the packages' own admin pages (`/admin/flags`, `/admin/settings`) | health-check, feature-flags, feature-flags-db, feature-flags-ui, maintenance-mode, settings, settings-db, settings-ui, audit-log |
| API contract | requests generated from the OpenAPI document, valid and deliberately broken, sent by the browser to the real API; every disagreement is shown | openapi-contract, property-testing-openapi |
| AI agent | a scripted MCP conversation about an order (no AI model: the shop's real MCP server answers), by an agent with permissions and one without (fail-closed); each call that reaches a tool is shown as its audit row | mcp, mcp-rbac-bridge, mcp-audit-log-bridge |
| Webhooks | the sender's attempt log next to the receiver's journal: HMAC signature, the deliberate 500, the retry | webhooks, webhooks-db, outbox-webhooks-bridge |
| Analytics | payment results in ClickHouse (`make ui-full`): the report covers shop-page orders, generated and scripted ones are counted apart; Grafana charts Prometheus and queries ClickHouse directly; a campaign report from PostgreSQL works in plain `make ui` too; the A/B test compares visitors and conversions per variant (in plain `make ui` its events wait in the outbox) | clickhouse-toolkit, utm-db, ab-testing-outbox, ab-testing-clickhouse, outbox-clickhouse |
| Tests | how the shop tests itself, from the reports the last `make testo`, `make property-test` and `make mutation` left under `build/`: the unit tests layer by layer, how each property test's generated cases fell across its branches, and the mutation gate — bugs planted on purpose, where, and a few of them with the test that caught each | testo, property-testing-testo, infection, mutation-history |

| Card | Amount | What happens |
|---|---|---|
| Regular card | 19.99 | approved, `paid` |
| Card with no funds | 40.02 | declined (402): final, not retried, `payment_failed` |
| Bank down briefly | 50.31 | the bank fails for **everyone** for ~12 s: retries, the breaker opens, the payment lands once it closes, `paid` |
| Bank down for long | 50.30 | the bank fails for everyone for ~45 s, longer than the outbox waits: `payment_failed`, and so does any order placed meanwhile |
| Bank answer lost | 50.40 | the bank takes the money but every answer is lost: the worker gives up (`payment_failed`), then the reconciliation asks the bank, finds the charge and the order becomes `paid` |

The outcome is chosen by the amount, the way real payment sandboxes use test
cards: the payment mock has no control endpoint. `make ui` adds a `worker`
service (Compose profile `ui`) that runs the outbox every second with
`OUTBOX_RETRY_DELAY_SECONDS=10` (three attempts then span the 15 s breaker
cooldown) and delivers each payment result through one fan-out to Centrifugo
and the webhook receiver, plus ClickHouse under `make ui-full`
(`RESULT_SINKS`). The webhook receiver refuses the first attempt of every event
(`WEBHOOK_FAIL_FIRST=1`), so a retry is always visible. Every other target keeps
the default delay of 0 and runs the worker by hand, so the `ui` worker must not
run alongside them: those scenarios refuse to start while it runs, and
`make ui-down` (or `make down`) stops it.

| Check | Proves |
|---|---|
| `make ui-smoke` | every page and its assets, the text keys of both languages, all four cards end to end, the fan-out (Centrifugo history, webhook refused then taken, signed), one trace from request to bank call |
| `make ui-full-smoke` | results reach ClickHouse once each although every one is delivered twice; a scripted order stays out of the report; Grafana's ClickHouse panel returns the shop orders; one visitor keeps their variant, a signed receipt converts once and a forged one never |
| `make ui-browser-smoke` | starts the `ui` stack, drives it from the host (Node 22+, Chrome) and stops it: no page throws, the order page receives the WebSocket push, the contract page finds no disagreement |

## The scenario

```text
POST /api/v1/checkout
  -> correlation id, maintenance gate, payments kill switch, idempotency claim
  -> SERVER span and RED metrics
  -> schema validation, RFC 9457 problem on failure
  -> one PostgreSQL transaction:
       order pending -> processing (FOR UPDATE)
       workflow transition row
       audit event (masked)
       payment.requested outbox row
  -> 202 Accepted
worker (sandbox:outbox:run)
  -> claims payment.requested, reopens the request's correlation scope
  -> context deadline 3s, bulkhead + retry + circuit breaker
  -> traced PSR-18 call to the payment mock
  -> second transaction: processing -> paid | payment_failed,
     audit event, order.paid | order.payment_failed outbox row
  -> no answer on the last attempt: payment.reconciliation_requested
worker (reconciliation)
  -> asks the bank what became of the charge (GET /payments/{key})
  -> charged: payment_failed -> paid in the saga step's transaction,
     order.paid outbox row
```

The external payment never runs inside a database transaction, so a rollback
cannot cancel a payment the provider already accepted. The outbox is
at-least-once: both the worker and the result handler are idempotent, and the
gateway call always carries a stable idempotency key.

How the worker classifies a gateway answer:

| Answer | Result |
|---|---|
| 2xx with `approved: true` | order `paid`, `order.paid` |
| 2xx with `approved: false`, or 402 | decline: order `payment_failed`, `order.payment_failed` |
| 5xx, 408, 409, 425, 429, transport error, non-JSON body | transient: retry, counted by the circuit breaker; the outbox redelivers |
| any other 4xx | the request itself was refused: not retried, order `payment_failed`, outbox row terminal |
| transient on the outbox's last attempt | order `payment_failed` (outcome `abandoned`), outbox row `failed`; the saga asks for a reconciliation, and a charge the bank turns out to hold makes the order `paid` (outcome `recovered`) |

Giving up is a deliberate choice: a timed-out call may still have charged.
The gateway idempotency key `payment-<sha256(orderId)>` (hashed: an order id
may hold characters a key must not) makes any later charge request
for the order return that same charge instead of a second one, and checkout
never restarts a non-`pending` order.

## Endpoints

| Endpoint | Purpose |
|---|---|
| `GET /live` | liveness, touches no dependency |
| `GET /ready`, `GET /health` | readiness: PostgreSQL, payment mock, Redis |
| `GET /metrics` | Prometheus exposition |
| `POST /api/v1/checkout` | accept an order for asynchronous payment; the request is validated against the OpenAPI document first (202; 400 — also a malformed `Idempotency-Key` or a non-JSON media type; 409 — an existing id with a different customer or amount, or the same key still in flight; 422 — the key reused with another body; 503) |
| `GET /api/v1/orders` | read model with whitelisted `specification` filters (`status`, `minAmountCents`, `customerId`, `sort=amount\|recent`) |
| `GET /api/v1/orders/{id}` | one order (200, 404) |
| `GET /api/v1/orders/{id}/timeline` | workflow transition history (200, 404) |
| `GET /api/v1/ui/orders/{id}` | the order page's backend: the evidence and, while the payment is open, the gateway protection, in one answer |
| `GET /api/v1/ui/tests` | the tests page's backend: the last unit and property runs and mutation gate as read from `build/`; a part is `null` until its target has run |
| `GET /api/v1/orders/{id}/evidence` | order, transitions, audit entries and outbox delivery state, without payloads; the web UI's source (200, 404) |
| `GET /files/{token}` | a stored file (the order receipt) behind a signed, expiring token, sent as a download (`Content-Disposition: attachment`); every failure is a 404 |
| `GET /api/v1/orders/{id}/trace` | the checkout's trace from Jaeger, whitelisted attributes only, under a 5 s request budget that `X-Request-Timeout-Ms` can only shorten (504 once spent) |
| `GET /api/v1/orders/{id}/realtime-token` | Centrifugo connection token subscribed to this order's channel only |
| `GET /api/v1/workflows/order` | the order workflow's places and transitions from the runtime definition |
| `GET /api/v1/resilience/payment-gateway` | breaker and bulkhead state read from Redis |
| `GET /api/v1/captcha` | whether the checkout asks for a reCAPTCHA answer (`CAPTCHA_ENABLED`) and the public site key |
| `GET /api/v1/operator` | flags, maintenance, settings, audit masker — read-only, no secrets |
| `GET/POST /admin/flags…`, `/admin/settings…` | the admin pages of `yii3-feature-flags-ui` and `yii3-settings-ui` over the `feature_flags` and `settings` tables (server-rendered, Bootstrap; no authentication) |
| `GET /api/v1/webhooks` | sender's webhook attempts and the receiver's journal |
| `GET /api/v1/analytics` | payment results from ClickHouse (`available: false` without it), the A/B test per variant, campaigns from PostgreSQL |
| `GET /api/v1/experiments/checkout-button` | the pay-button variant for this visitor (sets `ab_id` and the signed `ab_variants` cookie), the exposure recorded through the outbox, and a signed receipt the checkout sends back as `X-Ab-Receipt` |
| `GET /api/v1/contract/checkout-cases` | checkout requests generated from the OpenAPI document; nothing is sent |
| `GET /api/v1/agent/transcript` | an in-process MCP conversation by a permitted and an unpermitted agent, with the audit rows it wrote |
| `GET /`, `GET /ui/` | web UI (built-in server only) |
| `GET /api/v1/tenant` | tenant resolved from `X-Tenant-Id` |
| `POST /mcp` | MCP Streamable HTTP endpoint behind a shared secret (`X-Mcp-Secret`, `MCP_ENDPOINT_SECRET`); a real assistant connects with `claude mcp add --transport http sandbox http://127.0.0.1:8080/mcp --header "X-Mcp-Secret: sandbox-mcp-secret"` and gets `order.status`, `order.timeline`, `payment.gateway_status` |

The HTTP contract is generated from the actions' swagger-php attributes
(`#[OA\Get]`/`#[OA\Post]` on each `src/Endpoint/Rest/**/Action.php`, schemas in
`src/Endpoint/Rest/Shared/Swagger/`) by `yiisoft/yii-swagger`, served at
`/openapi.json` with Swagger UI at `/docs`, and enforced against the real
application by `rasuvaeff/openapi-contract`.

## Make targets

`make` alone (or `make help`) lists every target below with one line on what it does.

CI (`.github/workflows/build.yml`) runs every target below on each push and pull request: `check`, `testo` and `property-test` in one job, the mutation gate in another (its ledger travels between runs in the Actions cache, so the ratchet compares against the previous run), and each scenario in a job of its own, so each starts on a fresh volume. The quality and mutation jobs write a test report to the run's summary page (`bin/test-summary.php`, the same reader as the tests page) and keep the reports as artifacts: testo's JUnit and HTML, Infection's HTML report of every mutant.

### Baseline

| Target | What it proves |
|---|---|
| `make up` / `down` / `logs` / `shell` / `migrate` | stack lifecycle |
| `make smoke` | health, checkout, replay, worker, order becomes `paid` |
| `make checkout` / `checkout-replay` | one checkout; a repeated key creates no second payment event |
| `make inspect-order ID=<id>` | order, transitions, audit rows and outbox rows of one order |
| `make inspect-outbox` | outbox state per type and status |

### Scenarios

| Target | What it proves |
|---|---|
| `make simulate-race-condition` | 50 concurrent requests: one transition, one audit path, one payment event |
| `make mutation` | Infection over the domain and the use cases (MSI floor 95) plus a ratchet against the previous run (rasuvaeff/mutation-history): a mutant killed before must not escape now |
| `make simulate-lost-reply` | the payment saga's recovery: the bank takes the money and the answer is lost (5040); the worker gives up, the order fails, the reconciliation finds the charge and pays the order after all (`recover_payment`) — one charge at the bank, the order `payment_failed` then `paid`, the saga `recovered`, the partner told `payment_failed` and then `paid`, once each |
| `make simulate-network-collapse` | gateway delay beyond the deadline: bounded retries, breaker, recovery to `paid` after the breaker cooldown; then an outage longer than the outbox's attempts ends in `payment_failed` |
| `make simulate-kill-switch` | `payments.enabled` turned off on the `/admin/flags` form gives an RFC 9457 503 while `/live` stays up; turned back on, the same key is accepted |
| `make captcha-smoke` | the opt-in reCAPTCHA gate on the checkout against the payment mock's siteverify stand-in: no token or a forged one is a 403 and creates no order, a verified one reaches the checkout |
| `make simulate-maintenance` | maintenance 503 with `Retry-After`, health endpoints excluded, header bypass, and no 503 stored under a caller's idempotency key |
| `make correlation-smoke` | the id minted for the HTTP request is the id both audit rows (HTTP half and worker half) are filed under |

### Tests

| Target | Suite |
|---|---|
| `make testo` / `unit-test` | Testo unit suite: gateway mapping, worker outcome classification, workflow guards, outbox retry |
| `make property-test` | property suite: schema/validator parity, schema vs OpenAPI document, out-of-bounds payloads refused |
| `make contract-smoke` / `contract-test` | OpenAPI request/response validation over real HTTP (202, 200, 400, 404, 409), list filters applied, percent-encoded order ids |
| `make property-openapi-smoke` | 100 valid and 50 negative generated cases with a deterministic seed |
| `make testo-integration` / `integration-test` | Testo against live PostgreSQL and Redis |
| `make platform-smoke` | tenancy, A/B exposure, ClickHouse routing, file storage, reCAPTCHA semantics |

### Package profiles

| Target | Packages |
|---|---|
| `make settings-smoke` | `yii3-settings` typed runtime configuration |
| `make utm-smoke` | `yii3-utm` attribution journal and event deduplication; `make ui-smoke` drives the real path: `X-Utm-*` headers on the checkout, the story's attribution, the campaign report counting a replayed order once |
| `make webhook-smoke` / `webhook-http-smoke` | `yii3-webhooks` + `yii3-outbox-webhooks-bridge`, HMAC signature, retry after a 500 |
| `make db-adapters-smoke` | `yii3-webhooks-db`, `yii3-utm-db` on PostgreSQL |
| `make specification-smoke` | whitelisted read-model filters, parameterized values |
| `make payments-testing-smoke` | `payments-testing` provider contract without a network call |
| `make redis-smoke` / `redis-queue-smoke` | Redis-backed breaker and bulkhead; Redis Streams consumer group, `XAUTOCLAIM`, `XACK` |
| `make centrifugo-smoke` / `centrifugo-http-smoke` / `projection-smoke` | realtime projection of `order.paid` and `order.payment_failed`, in-memory and against real Centrifugo v6; no payment result left pending |
| `make mcp-smoke` / `mcp-rbac-smoke` / `mcp-http-smoke` / `mcp` | MCP tools, RBAC fail-closed, real Streamable HTTP endpoint; both RBAC and HTTP smokes find their call in `audit_log`, credited to `MCP_USER_ID` |
| `make observability-smoke` / `prometheus-outcomes-smoke` / `traces` / `metrics` | Prometheus series, Jaeger spans, `paid` / `failed` / `rejected` counters |
| `make analytics-smoke` / `analytics-roundtrip` | optional ClickHouse + Grafana profile and an `order.paid` export read back |

## Middleware order

```text
CorrelationId -> ErrorCatcher -> Otel -> RedMetrics -> ProblemDetails
  -> Maintenance -> Idempotency -> Tenancy -> Router
```

The order is load-bearing, not cosmetic:

- correlation is **outermost**: a response built above it never gets the
  `X-Request-ID` header, and a log record written above it is filed under no id
  at all. With `ProblemDetails` outside, a 400 problem response came back
  without the header while a 404 from the action had it;
- then the error boundary, so any failure below renders as RFC 9457 — inside
  the correlation scope the `ErrorHandler`'s logger reads;
- observability wraps the problem renderer, so it records the status the
  client gets: actions throw their 404/409/503 problems, and with
  `ProblemDetails` outside, RED metrics counted every one of them as a 500;
- observability wraps maintenance deliberately — a maintenance 503 is an
  operational signal and must stay visible in RED metrics and traces;
- maintenance precedes idempotency so that no claim is written to storage while
  the application is down.

What the targets actually assert: `make simulate-maintenance` checks the 503,
its `Retry-After`, its `X-Request-ID`, the open health endpoints, the header
bypass, the absence of the bypass token in the logs, and that the same key is
accepted afterwards; `make correlation-smoke` checks that the id of the HTTP
request is the id both audit rows carry. The last maintenance step pins a
package contract rather than our ordering: `yii3-idempotency` caches only 2xx
handler responses, so a 503 releases the claim in either order — verified by
swapping the two middlewares and watching the target still pass. The ordering
stays a precaution, and `make simulate-kill-switch` pins the same contract for
the 503 the action itself throws.

## One payload definition

`App\Endpoint\Rest\Ordering\Checkout\Input::schema()` is the single definition of the checkout
payload, consumed in three places so they cannot drift apart:

1. `parseOrThrow()` validates untrusted JSON on the HTTP edge and never echoes
   input values into errors;
2. `toJsonSchema()` is compared against the `CheckoutRequest` schema of
   the generated document by the property suite;
3. `arbitrary()` generates the valid values the property suite sends through
   the published contract.

The generated document remains the external HTTP contract: JSON Schema does not check
status codes, headers or response bodies — `openapi-contract` does.

## Observability

- `/metrics` — `yii3-metrics` + `yii3-metrics-prometheus` exposition;
- Jaeger UI — `http://127.0.0.1:16686`, OTLP/HTTP to port `4318`;
- Prometheus — `http://127.0.0.1:9090`;
- business counter `sandbox_payment_outcomes_total{outcome="paid|failed|rejected|abandoned|recovered"}`
  is written by the worker, not derived from HTTP status codes;
- `observability-smoke` also asserts that no sensitive attribute reaches a span.

## Configuration

| Variable | Default | Effect |
|---|---|---|
| `APP_PORT` | `8080` | published application port; the UI works from any host port or LAN hostname (Centrifugo accepts any origin) |
| `CENTRIFUGO_PORT`, `JAEGER_UI_PORT`, `PROMETHEUS_PORT`, `GRAFANA_PORT`, `CLICKHOUSE_PORT` | `8000`, `16686`, `9090`, `13000`, `18123` | published ports of the side services; the pages learn the Jaeger, Prometheus and Grafana ports from `/ui/ports.json`, and the WebSocket address (Centrifugo) comes with the realtime token. Centrifugo's publish API listens on the internal port 9000 and is never published |
| `OUTBOX_RETRY_DELAY_SECONDS` | `0` (`10` for the `ui` worker) | pause between outbox attempts, for payments and for result delivery alike |
| `RESULT_SINKS` | `centrifugo` (`centrifugo,webhooks,receipts` for `make ui`, plus `clickhouse` for `make ui-full`) | subscribers of the result fan-out, named explicitly |
| `FILES_SIGNING_KEY` | built-in demo value | signs receipt download URLs (at least 32 bytes) |
| `AB_EXPORT` | `0` (`1` for `make ui-full`) | the worker exports A/B events from the outbox to ClickHouse (`sandbox:ab:export`, which also creates the analytics tables once) |
| `AB_AGGREGATE_SECRET`, `AB_COOKIE_SECRET`, `AB_RECEIPT_SECRET` | built-in demo values | pseudonymous outbox aggregate ids, the sticky-variant cookie signature, the exposure receipt signature |
| `CENTRIFUGO_TOKEN_SECRET` | built-in demo value | signs the browser's connection token (at least 32 bytes); compose hands the same value to the app and to Centrifugo |
| `CAPTCHA_ENABLED`, `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET`, `RECAPTCHA_VERIFY_URL` | `false`, Google's v2 test keys, Google's siteverify | the opt-in reCAPTCHA gate on the checkout (`X-Captcha-Token`); off by default because the check needs the internet and every scripted checkout would fail it |
| `APP_PUBLIC_URL` | `http://127.0.0.1:8080` | base of the UI pages' canonical and Open Graph URLs (yii3-seo) |
| `APP_INDEXABLE` | `false` | `true` lets crawlers in through `/robots.txt`; leave it off until a deliberate deployment |
| `PAYMENTS_ENABLED` | `true` | the flag's first value, seeded into `feature_flags` by a data migration (`migrate:up`); afterwards the table (and `/admin/flags`) decides — `false` turns checkout into an RFC 9457 503 |
| `MAINTENANCE_ENABLED` | `false` | `true` returns 503 with `Retry-After` outside health paths |
| `MAINTENANCE_RETRY_AFTER` | `120` | seconds advertised in `Retry-After` |
| `MAINTENANCE_BYPASS_TOKEN` | `sandbox-maintenance-bypass` | value of `X-Maintenance-Bypass`, hashed into config |
| `PAYMENT_GATEWAY_MODE`, `PAYMENT_GATEWAY_TIMEOUT_MS`, `PAYMENT_GATEWAY_CONNECT_TIMEOUT_MS` | `sandbox`, `3000`, `1000` | typed through `yii3-settings` |
| `PAYMENT_MOCK_DELAY_MS`, `PAYMENT_MOCK_FAIL`, `PAYMENT_MOCK_UNAVAILABLE` | `0`, `0`, `0` | payment mock behaviour |
| `MCP_ENDPOINT_SECRET`, `MCP_USER_ID` | `sandbox-mcp-secret`, `demo-user` | MCP shared secret and demo identity |

See [.env.example](.env.example) for the full list.

## Limits

- the admin pages (`/admin/flags`, `/admin/settings`) have **no authentication
  and no CSRF protection**: they are for a sandbox on a developer's machine.
  The flag edit view is a copy (`resources/admin/flag-edit.php`) because the
  released `yii3-feature-flags-ui` reads `$csrf` unguarded and answers 500
  without a CSRF middleware;
- correlation IDs are minted here and a caller-supplied `X-Request-ID` is
  **not** trusted (`acceptIncoming: false`), which is the package default;
- `yii3-workflow-db` guarantees the uniqueness of a transition idempotency key;
  order concurrency is the application's own `SELECT ... FOR UPDATE`;
- `rasuvaeff/context` sets a cooperative deadline; it does not interrupt a
  blocking system call on its own. The deadline stops a new gateway attempt from
  starting; one attempt is bounded by `PAYMENT_GATEWAY_TIMEOUT_MS`, because PSR-18
  has no per-request timeout. An approval that arrives after the deadline still
  pays the order;
- Kafka is deliberately absent: no monorepo package implements a Kafka
  transport, so the runnable queue profile uses Redis Streams;
- DB-backed tenancy and A/B registries, browser A/B cookies, file upload
  endpoints remain separate profiles outside the checkout path;
- the Stripe and PayPal adapters are not part of the sandbox: they are not
  published, and the sandbox only uses released packages.
