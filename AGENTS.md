# AGENTS.md — ecosystem sandbox

Guidance for AI agents working on this application. Read before changing code.

## What this is

A runnable Yii3 application (`App\` namespace, `src/`) that composes the
monorepo's packages into one checkout scenario: HTTP checkout, a PostgreSQL
transaction, an outbox, a payment worker with resilience policies, telemetry,
metrics, MCP and a set of optional profiles. It is **not** a Composer package —
`composer.lock` is committed, dependencies are the released `rasuvaeff/*`
versions from Packagist, and nothing here is published. A change the sandbox
needs in a package is released there first; the sandbox then bumps its
constraint.

The plan this application implements is `_plans/DOCKER-DEMO-PLAN.md` in the
rasuvaeff monorepo (not part of this repository). Every "done" claim in that
plan must name the `make` target that proves it.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green run
   of the affected target. "Should work" does not count. The baseline is
   `make smoke`; a change to a profile also runs that profile's target.
2. **No suppressions.** No `@psalm-suppress`, no silenced errors. Fix the root
   cause.
3. **A demonstration asserts an effect, not a status code.** Every scenario
   checks state or evidence: rows in PostgreSQL, outbox status, a metric
   sample, a span attribute, an audit `request_id`. A script that only prints
   an HTTP status proves nothing and does not belong here.
4. **Never weaken a guarantee to make a demo pass.** The external payment stays
   outside the database transaction, the outbox stays at-least-once, the
   gateway call keeps its idempotency key.

## Commands

No PHP/Composer on the host — everything runs in Docker Compose from this
directory. The image is PHP 8.5, pinned (`~8.5.0`, `config.platform.php`).
`make check` runs php-cs-fixer, psalm (level 1, no suppressions, no
baseline), deptrac (`deptrac.yaml` layers, `deptrac-contexts.yaml` slices),
rector and `bin/support/class-references.php` (every `App\…` name in
config/, bin/, tests/ exists — psalm reads src/ only); it is part of every
"done". psalm does not read `tests/`: an import php-cs-fixer removed as
unused while a test was half-written fails only at run time.

```bash
make smoke                 # baseline: health, checkout, replay, worker
make testo                 # unit suite (architecture rules included)
make mutation              # Infection over Domain + Application, minMsi 95, ratchet
make property-test         # property suite
make contract-smoke        # OpenAPI request/response over real HTTP
make testo-integration     # Testo against live PostgreSQL and Redis
make correlation-smoke     # checkout and worker share one correlation id
make simulate-maintenance  # 503 + Retry-After, health open, no 503 replayed
make inspect-order ID=<id> # database evidence for one order
```

After editing `composer.json`:

```bash
docker compose run --rm --no-deps app composer update <package> -W
```

A quick syntax check without the stack:

```bash
docker compose run --rm --no-deps app php -l <file>
```

## Invariants & gotchas

- **Middleware order is load-bearing.** `CorrelationId → ErrorCatcher →
  Otel → RedMetrics → ProblemDetails → Maintenance → Idempotency → Tenancy →
  Router → RouteNotFound`. Observability wraps the problem renderer so it records the rendered
  status: actions throw their problems, and a thrown 404 seen by RedMetrics is
  a 500. Correlation is outermost because a response built above it gets no
  `X-Request-ID` and a log record written above it is filed under no id (with
  `ProblemDetails` outside, a 400 problem response lost the header while a 404
  from the action kept it). Maintenance stays before idempotency so no claim is
  written to storage while the application is down — a precaution, not a
  provable invariant: `yii3-idempotency` caches only 2xx responses, so a 503
  releases the claim in either order (swapping the two leaves
  `simulate-maintenance` green). Each position is commented in
  `config/web/di/application.php`; what the targets really assert is listed in
  the README.
- **The checkout payload has one definition.**
  `App\Endpoint\Rest\Ordering\Checkout\Input::schema()` (rasuvaeff/schema,
  bounds from the domain's `OrderId`/`CustomerId`/`Amount`) parses the body,
  is compared against the published `CheckoutRequest` schema
  (`CheckoutInputPropertyTest`) and generates the property payloads. Never
  inline a second copy of those constraints in an action or a test.
- **The OpenAPI document is generated from the actions' attributes**
  (`#[OA\Get]`/`#[OA\Post]` on each `Action::__invoke`, component schemas in
  `src/Endpoint/Rest/Shared/Swagger/`, yiisoft/yii-swagger), served at
  `/openapi.json`, and it validates the checkout request edge: the checkout
  action runs `Contract::validateRequest()` before anything else, so media
  type, header lengths and patterns live in the attributes only; do not add a
  hand-coded copy of such a rule to the action (the contract page would then
  report a disagreement the moment the two drift).
- **One recipe assembles the in-process MCP server.** `App\Endpoint\Mcp\Assistant\AgentFactory`
  builds the RBAC-wired server for both the agent page and
  `bin/mcp-rbac-smoke.php`; a new interceptor goes there, nowhere else (and
  into `params['rasuvaeff/yii3-mcp']['interceptors']` for `/mcp`). The audit
  trail is the outermost interceptor of both, credited through
  `IdentityAuditActorResolver`; a tool hidden by RBAC visibility never reaches
  an interceptor, so a refused agent leaves no audit row, by design.
- **Host ports come from `/ui/ports.json`** (Jaeger, Prometheus, Grafana:
  compose passes `JAEGER_UI_PORT`/`PROMETHEUS_PORT`/`GRAFANA_PORT` to `app`,
  `public/index.php` serves them, pages link by `data-service`), and the
  WebSocket address comes with the realtime token (`CENTRIFUGO_PUBLIC_PORT`).
  Never hardcode a side service's port in the UI; Centrifugo accepts any
  origin for the same reason (`APP_PORT` and LAN hostnames are documented in
  the README's configuration table).
- **New endpoint ⇒ its `#[OA\…]` attribute ⇒ contract smoke.** `property-openapi-smoke`
  generates requests from the document, so an undocumented response shape or a
  missing 404 surfaces as a generated failure, not as a silent gap.
- **Correlation crosses the process boundary through the outbox payload.** The
  HTTP middleware's scope ends with the 202; `PublishOrderIntegrationEvents`
  writes `correlationId` into the `payment.requested` payload and
  `PaymentRequestedConsumer` re-opens the scope with `runWith()`. The id is re-validated there — a payload
  is untrusted input.
- **MCP tools read the same sources as HTTP.** `ShopTools` resolves
  through the real container (`bin/support/container.php` in `mcp-smoke` and
  `mcp-rbac-smoke`) and must never answer from a hardcoded value: an agent and
  an operator have to see one truth: tools and actions call the same query
  handlers (`order.timeline` and `GET /orders/{id}/timeline` share
  `GetOrderTimelineHandler`). Both smokes assert that a missing order comes back as
  `found: false`, which is what makes a reintroduced fake fail.
- **UI pages are server-rendered views with their own head.**
  `resources/views/ui/<page>.php` is the body, `resources/views/layout/ui.php`
  the head; `Endpoint\Web\Ui\UiPage` names each page's title, description,
  script and whether it is personal (`noindex`), and yii3-seo turns that into
  title, description, canonical, Open Graph and JSON-LD. The URLs stay
  `/ui/<page>.html`; scripts, styles and `i18n.json` are static files in
  `public/ui/`. A new page is a `UiPage` case plus a view; `ui-smoke` checks
  every case's head.
- **The web UI shows only evidence.** `public/ui/` renders every step from
  `GET /api/v1/orders/{id}/evidence`; never add a client-side timer that moves
  a step on its own, and never hardcode a policy number (attempts, cooldown)
  in a text — `maxAttempts` comes from the endpoint. A new text key goes into
  both `ru` and `en` of `i18n.json`; `make ui-smoke` fails otherwise.
- **UTM capture is route-level, on the checkout only.** `UtmCaptureMiddleware`
  reads the `X-Utm-*` headers the shop page sends (the browser keeps the
  campaign in `localStorage`; `UtmHistoryStore` is bound to the null store, so
  the API sets no cookie). The attribution row is written inside the order's
  transaction. Reports count only shop-page orders (`customer_id =
  OrderSource::SHOP_CUSTOMER_ID`), because the contract page
  sends generated `X-Utm-*` headers too.
- **A/B events ride the outbox, and only the exporter takes them.** The
  exposure (`RecordExposureHandler`, in its own transaction) and the conversion (in
  the checkout's transaction, from the signed `X-Ab-Receipt`) are outbox rows of
  types `ab.exposure`/`ab.conversion`. The payment Processor and the result
  fan-out claim disjoint types; `sandbox:ab:export` claims these through
  `yii3-outbox-clickhouse` and runs only with `AB_EXPORT=1` (`make ui-full`).
  The visitor and sticky-variant middleware are route-level on the experiment
  endpoint: global placement would put `Set-Cookie` on every API answer,
  idempotency replays included. Never add the receipt to the checkout body —
  the checkout's `Input` is the single payload definition.
- **A 500 is logged by `App\Infrastructure\Shared\Http\ServerErrorReporter`.** `ProblemDetailsMiddleware`
  catches everything below it, so the `ErrorCatcher` outside never sees an
  exception; without the reporter an unhandled error left no log line at all.
  Problems the application throws on purpose (4xx, the kill-switch 503) are not
  logged.
- **One receipt per paid order, whatever the redeliveries.** `StoreReceiptOnOrderPaid`
  is a fan-out sink; `Storage::add()` has no natural key, so the existing
  attachment (`order`/`receipt`) is the dedupe check, and the file row plus its
  link are written in one transaction. Downloads go through
  `/files/{token}` only; `FileDownloadAction` (yii3-filestorage-web 0.2)
  reads the token from `CurrentRoute` itself.
- **A request budget does not cross the outbox.** `App\Infrastructure\Operations\Http\RequestBudget`
  (rasuvaeff/context-http) is route-level on the trace read, which calls
  Jaeger; the checkout answers 202 before the payment starts, so its budget
  cannot honestly reach the gateway — the worker has its own deadline. The
  wrapper hands ContextMiddleware the id CorrelationIdMiddleware minted, so a
  request never carries two correlation ids and a caller cannot pick one.
- **Flags and settings have one store each, the tables the admin pages write.**
  `yii3-feature-flags-db` binds `FlagProvider`, `yii3-settings-db` binds
  `SettingsProvider`; the application must not bind either (an app binding
  wins silently, and the admin page would write a table the checkout never
  reads). A data migration seeds params flags into `feature_flags` once, so
  `PAYMENTS_ENABLED` is a first value, not a switch; `simulate-kill-switch`
  flips the flag through the admin form. The admin routes need `$params`
  inside `routes.php` (`router.php` passes it).
- **The captcha gate is opt-in and never on by default.** `App\Infrastructure\Operations\Http\CaptchaGate`
  is route-level on the checkout and runs only with `CAPTCHA_ENABLED=true`:
  the check calls Google, and every scripted scenario, the contract page and
  `property-openapi-smoke` post checkouts without a browser. `captcha-smoke`
  points `RECAPTCHA_VERIFY_URL` at the payment mock's siteverify stand-in. A
  replay passes without a new answer because the idempotency cache sits
  outside the route.
- **The `ui` worker must not share the outbox with another target.** It runs
  the outbox every second with a 10 s retry delay; every other target runs the
  worker by hand with delay 0 and expects to own every message. Those scripts
  source `bin/support/require-no-ui-worker.sh` and refuse to start while it
  runs; a new scenario that runs the worker sources it too. `make ui-down` and
  `make down` stop it — a bare `docker compose down` does not.
- **Payment results reach their subscribers through one fan-out.**
  `ResultFanOut` serves Centrifugo, webhooks, receipts and ClickHouse from a
  single Processor (`sandbox:projections:run`), because the outbox hands a
  message to one claimer. Sinks are named in `RESULT_SINKS`, never inferred from
  what is reachable; each must dedupe on the message id, since one failing sink
  retries the message for all.
- **One trace spans the outbox.** `PublishOrderIntegrationEvents` writes the checkout's trace
  context into the `payment.requested` payload; `PaymentRequestedConsumer` opens a
  CONSUMER span under it. The bank never gets `traceparent`.
- **MCP tools run inside a PHP Fiber** (mcp/sdk creates one per request
  handler). OpenTelemetry needs `ext-ffi` and `OTEL_PHP_FIBERS_ENABLED=true` to
  bind its context to it; without them a traced call there warns, and the web
  runtime turns the warning into an exception — `payment.gateway_status` failed
  on `/mcp` until the image got ffi. OpenTelemetry reads the flag from
  `$_SERVER` while the autoloader runs, and the built-in server never copies
  the environment there, so `public/index.php` does it first. The requirement
  is documented in yii3-mcp-telemetry-bridge.
- **Problems are thrown, not built.** `App\Shared\Http\Problem` owns every RFC 9457
  type URI and title; actions `throw Problem::orderNotFound()` and
  `ProblemDetailsMiddleware` renders it. A hand-built `application/problem+json`
  body in an action is a regression (TenantAction once answered a bare 404).
- **The deadline belongs to the pipeline.** `ResilientPaymentGateway` passes its
  `Context` to `CompiledPipeline::call()`; Retry checks it before each attempt,
  outside the breaker. An `assertActive()` inside the callback would turn one
  slow answer into breaker failures. The deadline is derived from
  `payment.gateway_timeout_ms` in DI, never a literal.
- **`MCP_USER_ID` is the one permitted identity.** `AgentIdentities` takes
  it from DI (`AssistantBootloader`); the agent page, `/mcp` and `mcp-rbac-smoke` all read it from
  there. Hardcoding `demo-user` anywhere splits that truth.
- **The UI's read endpoints never leak.** No Centrifugo API key, webhook or MCP
  secret, bypass hash or outbox payload; Jaeger attributes are whitelisted;
  the MCP transcript runs in-process and never through `/mcp`; a health check
  reports an exception by class only (a PDO message names host and user); the
  webhook view masks the endpoint and scrubs `last_error` with the bridge's
  `UrlMasker` (public API since yii3-outbox-webhooks-bridge 1.2), as the
  bridge does for its logs — never a second copy of that redaction.
- **Test amounts live in `mock-payment/index.php`:** 4002 declines, 5031 and
  5030 start a short and a long outage for every charge, 5040 takes the money
  and loses the answer (every reply for that key is a 503). Do not reuse them
  in another scenario; `GenerateCheckoutCases::TEST_AMOUNTS` keeps the
  contract page off them.
- **Reports read Analytics' own projection.** `order_summary` is kept by
  `OrderSummaryProjector` from `OrderPlaced`, `OrderStatusChanged` and
  Marketing's `CheckoutAttributed` (the channel is resolved once, from the
  real touchpoint), inside the transaction of the change, so a report read
  right after a checkout sees it. The campaign report reads that table alone;
  no report joins another context's tables. Built going forward: there is no
  event store to replay. `OrderSummaryProjectionTest` holds the report to a
  count over the same events.
- **The order page has its own backend.** `GET /api/v1/ui/orders/{id}` is one
  request per refresh: the evidence (`OrderEvidenceComposer`, shared with the
  evidence endpoint) and the breaker only while the order is open, decided by
  `OrderPolicies::settled()`. A screen that needs a new piece of data gets it
  here, not through another request from the page.
- **The payment saga reconciles what it cannot know.** `PaymentProcess`
  (table `payment_processes`) follows each payment: a definite answer
  (approved, 402, 422) settles it; only an unanswered last attempt abandons
  it, and abandoning records `payment.reconciliation_requested`, which the
  payment worker takes (`RoutingPublisher`). The reconciliation asks the bank
  (`GET /payments/{key}`) and, when the money was taken, pays the failed
  order after all: `PaymentRecovered` → `recover_payment`
  (`payment_failed → paid`, idempotency key `recover-<gateway key>`), in the
  same transaction as the saga's step, and the partner hears `order.paid`
  after `order.payment_failed`. The saga never refunds: an order id is one
  purchase and checkout never restarts a failed one, so the found charge is
  the only one. A charge found before the decline has failed the order is
  refused by throwing (`PaymentNotFailedYetException`), the transaction rolls
  back and the outbox repeats it. Bank calls never run inside a transaction; they go
  through their own pipeline, outside the charge's breaker, which the lost
  answers themselves open. `make simulate-lost-reply` checks the ledger, the
  order, the saga and the partner.
- **Integration events go through one registry.** Every event the outbox
  carries is an `IntegrationEvent` class (`Application\Ordering\Integration\
  PaymentRequested`, `OrderPaid`, `OrderPaymentFailed`) with its frozen type,
  a version and one rasuvaeff/schema definition; producers publish through
  `IntegrationEventBus`, consumers parse through `IntegrationMessages` /
  `IntegrationEventRegistry` (registered in `SharedBootloader`). Never decode
  an outbox payload by hand. A changed shape is a new class under a new type
  (`type@v2`), never an edit; `IntegrationEventContractTest` checks that
  what the producer writes the registry reads back.
- **Every result sink sits behind the inbox.** `ResultFanOut` wraps each
  sink in `IdempotentConsumer` under its `RESULT_SINKS` name: a sink that
  delivered is not run again when another sink makes the message retry.
  The effect runs outside our transaction (no PostgreSQL transaction across
  a network call) and is recorded in `inbox` after it succeeded, so each sink
  keeps its own dedupe for the crash window. `ui-full-smoke` asserts one
  inbox row per sink per result.
- **Order rules are specifications, written once.** `OrderPolicies`
  (`settled`, `open`, `payable`, `canTake`) are rasuvaeff/specification
  trees: `Order::satisfies()` checks them in memory through
  `InMemorySpecification`, the read models translate the same object to SQL
  (`GET /api/v1/orders?state=open|settled`). `OrderPoliciesSqlTest` holds the
  two interpreters to the same answer on PostgreSQL. Do not re-state a rule
  as a status comparison.
- **Expected refusals are values.** A command the domain declines returns a
  `Rasuvaeff\Result\Result` with a `Refusal` (`TransitionRefused`,
  `PayloadConflict`, `AlreadySettled`); `#[\NoDiscard]` keeps a caller from
  dropping it. Exceptions stay for a lookup that finds nothing at a query
  boundary (`OrderNotFoundException`), a broken invariant and failing systems.
- **Other contexts reach Ordering through `OrderingApi`.** Payments,
  Analytics and the agent's tools read orders only through the facade (and
  the published values: VOs, `OrderView`, `TransitionRecord`, events);
  `deptrac-contexts.yaml` refuses Ordering's handlers, aggregate and
  repositories to them.
- **The domain and the use cases are mutation-gated.** `make mutation` runs
  Infection (pcov, off everywhere else) over `src/Domain` and
  `src/Application` with the `Mutation` suite (Unit + Property, no running
  service), `minMsi: 95` as the floor, and `bin/mutation-gate.sh` ratchets
  every run against the previous one with rasuvaeff/mutation-history: a
  mutant killed before that escapes now fails the gate, whatever the MSI.
  The ledger lives in `build/mutation-history` (one per environment: ids
  carry the absolute path). A change to Domain or Application runs it.
  testo maps mutants to tests through `#[Covers]`: a class no test covers
  yields **no mutant at all**, and the gate passes without looking at it
  (the tests page's classes did, 0 of their 38 mutants counted, until
  `GetTestReportHandlerTest` covered them). A new Domain or Application
  class gets a test with its `#[Covers]` in the same change.
- **The tests page reads the tools' reports, never runs them.** `make testo`
  and `make property-test` write JUnit and HTML to `build/reports/`, `make
  mutation` leaves Infection's `build/infection-log.json` and exports the
  ratchet's trend to `build/reports/mutation-trend.tsv`; `FileTestReports`
  (behind `Application\Operations\GetTestReport\TestReports`) parses them for
  `/ui/tests.html` and for the CI summary (`bin/test-summary.php`) alike. The
  test tools are dev dependencies: `src/` reads their files, never their
  classes. The MSI floor shown is `infection.json5`'s `minMsi`, read, not
  restated.
- **Architecture rules are tests.** `tests/Unit/Architecture/ArchitectureTest`
  checks what deptrac cannot: one `handle()` per final readonly handler,
  commands and queries as values, an `#[OA\…]` on every REST action, no
  system clock, no container in a constructor, no `#[\Override]`, every
  integration event registered. A new rule in this file gets a test there.
- **No service locator in application code.** Nothing under `src/` takes a
  `ContainerInterface` to fetch a dependency: a class names what it needs in
  its constructor, and a choice made at boot (the result sinks of
  `RESULT_SINKS`) is a DI factory with typed parameters. The one PSR container
  `src/` builds, `Endpoint\Mcp\Assistant\ToolContainer`, is what
  yii3-mcp's `McpServerFactory` requires, a fixed map holding the one tool
  object already resolved. `bin/support/container.php` is the scripts' and
  tests' composition root, not a locator for `src/`.
- **`messaging` means Redis Streams here.** No monorepo package implements a
  Kafka transport; do not add a Compose profile that implies one.
- **Migrations are the stock tools.**
  PostgreSQL: yiisoft/db-migration's `./yii migrate:up -n` over
  `params['yiisoft/db-migration']['sourceNamespaces']` — every package's
  migration namespace plus the application's one, `App\Infrastructure\Migration`
  (`RevertibleMigrationInterface`, columns through `$b->columnBuilder()`,
  times as `datetimeWithTimezone()`; the params flags are seeded by a data
  migration, once). ClickHouse: yii3-clickhouse-toolkit's
  `./yii clickhouse:migrations:migrate` over
  `src/Infrastructure/Migration/ClickHouse/*.sql`; the A/B tables are
  yii3-ab-testing-clickhouse's own `SchemaMigrations`, applied by
  `sandbox:ab:export`. A table is never created from application code. A
  volume made before a migration moved has a history that names the old
  class: `make down` (it drops the volumes) once.
- **Table names live in `App\Domain\Shared\TableNames`**, the packages' too:
  params hand them to each `*-db` package, and read models that join a
  package's table use the same constant, so the two cannot disagree.
- **Time comes from `Psr\Clock\ClockInterface`.** Nothing in `src/` calls
  `new DateTimeImmutable()`, `time()` or a database `CURRENT_TIMESTAMP` for
  a value it writes; tests pass yiisoft/test-support's `StaticClock`.
- **`vendor/` and `composer.lock` are written by the container as root.** A
  host-side edit of those paths needs `sudo` or a container; prefer running
  composer in the container.
- Code: `declare(strict_types=1)`, `final readonly class` where possible,
  explicit types, named arguments; layers and slices as in
  `docs/architecture.md`. **No `#[\Override]`** (the application's
  convention; psalm runs with `ensureOverrideAttribute="false"` — the
  monorepo's package rule does not apply to this application).
  **`#[\SensitiveParameter]` on every parameter that carries a secret**
  (a key, a token, a signature, a credential, a default that is a demo
  secret), so it never reaches a stack trace or a log.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit) when
  behaviour, targets or configuration change.
- Update the matching phase status in the monorepo's `_plans/DOCKER-DEMO-PLAN.md`,
  naming the target that verifies it.
- Re-run `make smoke` plus every target your change touches, and paste the
  output.
