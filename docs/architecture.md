# Architecture

The sandbox demonstrates the `rasuvaeff/*` packages, and its own code is meant
to be read as an example too: domain-driven, cut into vertical slices, with
commands and queries separated and the contexts talking through events. There
is no ActiveRecord (plain domain objects, yiisoft/db with
`rasuvaeff/specification` for queries) and no command bus (endpoints get their
handler injected).

## Layers

| Layer | Holds | May use |
|---|---|---|
| `App\Domain\<Context>` | aggregates, value objects, enums, domain events, exceptions, repository and port interfaces, specifications | itself, `rasuvaeff/specification` |
| `App\Application\<Context>\<UseCase>` | `*Command`/`*Query` + `*Handler` (one `handle()`), ports of their own, `Event/` listeners | Domain, Shared |
| `App\Infrastructure\<Context>` | repositories (yiisoft/db Query + specification), read models, adapters to packages and services, migrations | Domain, Application, Shared, vendors |
| `App\Endpoint\{Rest,Console,Mcp}\<Context>\<Action>` | `Action` (+ `Input` schema, `#[OA\…]` attributes); no SQL, no business rule | Application, Domain, Shared |
| `App\Shared` | problem responses, JSON responder, header names, query parameters | itself |
| `App\Bootloader` | interface → implementation bindings (`di-providers`) | everything |

`deptrac.yaml` enforces the layers and fences the persistence vendors (yiisoft/db,
Predis, ClickHouse) out of Domain, Application and Endpoint.

## Contexts

| Context | Owns | Publishes |
|---|---|---|
| Ordering | `Order` aggregate and its lifecycle (yii3-workflow), checkout, evidence/timeline/list read models, the `orders` table | value objects, `OrderPlaced`/`OrderStatusChanged`, read-side queries, integration events (`payment.requested`, `order.paid`, `order.payment_failed`), `OrdersTable` as a read model |
| Payments | capture handler, gateway adapter behind the resilience pipeline, receipts, outcome metrics | `PaymentCaptured`, `PaymentDeclined` |
| Marketing | UTM attribution, the pay-button A/B experiment | `CheckoutConversion::GOAL` |
| Analytics | ClickHouse export and reports (totals, campaigns, experiment), Grafana status | — |
| Assistant | MCP tools, the in-process agent, its audit trail, RBAC identities | — |
| Operations | health checks, request budget, captcha gate, trace view, operator panel | — |

`deptrac-contexts.yaml` lets a context see only what another one publishes:
`OrderingPublic` (value objects, exceptions, domain events, the
`OrderingApi` facade with `OrderView`/`ListOrdersQuery`, the integration
events), `PaymentsEvents`, `MarketingPublic`. Ordering hears back from Payments only through
`PaymentsEvents`. Endpoint and Bootloader compose contexts on purpose (BFF
reads, DI wiring) and are left out of the slice rules.

## Commands, queries, events

- A command handler loads the aggregate, calls it and saves it inside one
  `TransactionManager::execute()`, then dispatches the aggregate's released
  events through yii-event (`config/common/events.php`), still inside the
  transaction.
- Listeners do the cross-cutting work in that transaction:
  `PublishOrderIntegrationEvents` maps `OrderStatusChanged` to an outbox row,
  `AuditOrderStatusChanges` writes the audit log, `CreditOrderToMarketing`
  records attribution and the A/B conversion.
- Integration events are classes (`IntegrationEvent`: frozen type, version,
  one rasuvaeff/schema definition, `toPayload()`), published through
  `IntegrationEventBus` (the outbox) and parsed by consumers only through the
  `IntegrationEventRegistry` (`type@vN`); a property test holds producer and
  consumer to the same schema.
- Integration event types and payloads are a frozen contract
  (`payment.requested` with `correlationId` and `trace`, `order.paid`,
  `order.payment_failed`, `ab.exposure`, `ab.conversion`); a domain event may
  be renamed, an outbox type never.
- Outbox consumers are integration-event handlers: `PaymentRequestedConsumer`
  calls `CapturePaymentHandler`, whose `PaymentCaptured`/`PaymentDeclined`
  Ordering's `ApplyPaymentOutcome` turns into the next status; `ResultFanOut`
  serves the result subscribers; `sandbox:ab:export` the A/B events.
- Queries never go through an aggregate: they read the tables they need
  (read models), through `rasuvaeff/specification` where filters are dynamic.

## Rules, refusals, idempotency

- Business rules over an order are `OrderPolicies` specifications: the
  aggregate evaluates them in memory (`InMemorySpecification`), the read
  models in SQL (`QueryBuildingVisitor`); a property test on PostgreSQL holds
  the two to one answer.
- A command the domain declines returns `Result<…, Refusal>`
  (rasuvaeff/result) marked `#[\NoDiscard]`; the endpoint maps each refusal
  to its problem. Queries that find nothing throw.
- Result sinks are idempotent consumers over an inbox table: run, then
  record, never across a network call inside a transaction.
- The payment is a saga (`PaymentProcess`, a process manager): its steps are
  values refused from the wrong status, so duplicated or late events change
  nothing. An unanswered last attempt abandons it and asks for a
  reconciliation; a charge found then pays the failed order after all
  (`recover_payment`, in the saga step's transaction).

## Read models and screens

- Analytics keeps its own projection (`order_summary`) from domain events of
  Ordering and Marketing, in the transaction of the change; reports never
  join another context's tables.
- The web UI's order page has a backend endpoint (`/api/v1/ui/orders/{id}`):
  one request per screen, composed in the Endpoint layer.

## Dependencies

No service locator in `src/`: a class names what it needs in its
constructor, and a choice made at boot (`RESULT_SINKS`) is a DI factory with
typed parameters. The one PSR container `src/` builds, `ToolContainer`, is a
fixed map that yii3-mcp's `McpServerFactory` requires.

## Persistence

- PostgreSQL schema: yiisoft/db-migration, `./yii migrate:up -n`, over every
  package's migration namespace and the application's own
  (`App\Infrastructure\Migration`; table names from
  `App\Domain\Shared\TableNames`, which also configures the packages' tables)
  (`params['yiisoft/db-migration']['sourceNamespaces']`). The params flags are
  seeded by a data migration, once.
- ClickHouse schema: yii3-clickhouse-toolkit, `./yii
  clickhouse:migrations:migrate`, over
  `src/Infrastructure/Migration/ClickHouse/*.sql`; the A/B tables are
  yii3-ab-testing-clickhouse's `SchemaMigrations`.
- ClickHouse reads: clickhouse-toolkit's `select()` builder (1.12, built for
  this sandbox's reads — clickhouse-toolkit#45): validated identifiers,
  aggregates and scalars from closed sets, `FINAL`, `GROUP BY`, mandatory
  filters, every value and the LIMIT bound as `{name:Type}`. The one join (the
  A/B variant report) stays a constant skeleton with bound values — the
  builder has no join by design.

## Untrusted input

Every request body, query parameter, outbox payload and foreign answer
(Jaeger, Redis, ClickHouse, webhook receiver, audit rows) is parsed through
`rasuvaeff/schema` or a typed accessor before it reaches application code.
The OpenAPI document, generated from the actions' attributes, is the
statement of the HTTP contract; the checkout is validated against it before
parsing.

## Tests

- Property tests (`rasuvaeff/property-testing-testo`): the `Order` aggregate as
  a rule-based state machine (`Gen::rules(OrderMachine::class)`), the workflow
  adapter against the domain's transition table, value-object bounds, the
  checkout `Input` round trip and its parity with the published schema,
  outcome mapping of the capture handler, the fan-out.
  Branchy properties carry `Classify::cover` gates and `*Examples()` edge cases.
- `rasuvaeff/understudy` doubles for ports (gateway, dispatcher, lifecycle,
  transactions); `tests/Support/InMemoryOrderRepository` for the repository.
- `make check` (cs, psalm level 1 without suppressions, both deptrac files,
  rector, class references) is the static gate; the `make` targets are the
  behavioural one; `make mutation` (Infection over Domain + Application,
  MSI 100% at the time of writing, floor 95, ratcheted per mutant) proves
  the tests would notice a change; `ArchitectureTest` holds the rules
  deptrac cannot express.
