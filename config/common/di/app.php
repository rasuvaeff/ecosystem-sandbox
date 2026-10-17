<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner as BulkheadRedisScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock as BreakerClock;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Redis\PredisScriptRunner as CircuitRedisScriptRunner;
use Rasuvaeff\CircuitBreaker\RedisStorage as CircuitRedisStorage;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\CompiledPipeline;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Yii3Centrifugo\CentrifugoClient;
use Rasuvaeff\Yii3Centrifugo\CentrifugoClientInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Rasuvaeff\Yii3HealthCheck\HealthChecker;
use Rasuvaeff\Yii3McpRbacBridge\PermissionMap;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Rasuvaeff\Yii3Metrics\RedMetricsMiddleware;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3Settings\Settings;
use Rasuvaeff\Yii3Telemetry\HttpClientSpanDecorator;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3Tenancy\ConfigTenantProvider;
use Rasuvaeff\Yii3Tenancy\TenantProvider;
use Rasuvaeff\Yii3WorkflowDb\WorkflowTransaction;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Log\ContextProvider\CompositeContextProvider;
use Yiisoft\Log\ContextProvider\SystemContextProvider;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

// The pipeline's in-process attempts at the gateway; the worker's deadline is
// sized from the same number, so raising one cannot starve the other.
$gatewayAttempts = 3;

return [
    // The correlation ID reaches the logs through the package's own context
    // provider; every record of a request carries `requestId`, and the worker
    // inherits it from the outbox message (see PaymentRequestedConsumer).
    LoggerInterface::class => static fn (CorrelationIdContextProvider $correlationContext): Logger => new Logger(
        [new StreamTarget('php://stderr')],
        new CompositeContextProvider(new SystemContextProvider(), $correlationContext),
    ),
    TenantProvider::class => static fn (): TenantProvider => new ConfigTenantProvider(
        definitions: (array) ($params['rasuvaeff/yii3-tenancy']['tenants'] ?? []),
    ),
    // The checkout is an API call: the browser keeps the campaign history
    // (localStorage) and sends the touchpoint as X-Utm-* headers, so the
    // server writes no cookie of its own.
    Rasuvaeff\Yii3Utm\UtmHistoryStore::class => Rasuvaeff\Yii3Utm\NullUtmHistoryStore::class,
    Rasuvaeff\Yii3AbTesting\ExperimentProvider::class => static fn (): Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider => new Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider(
        config: (array) ($params['rasuvaeff/yii3-ab-testing']['experiments'] ?? []),
    ),
    // The sandbox is served over plain HTTP (a LAN address included); a
    // Secure cookie would be dropped there, minting a new visitor — and
    // possibly a new variant — on every page view.
    Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware::class => static fn (): Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware => new Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware(
        secure: false,
    ),
    Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware::class => static fn (Rasuvaeff\Yii3AbTesting\AbTesting $ab): Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware => new Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware(
        resolver: $ab,
        signer: new Yiisoft\Cookies\CookieSigner(key: App\Environment::string(name: 'AB_COOKIE_SECRET', default: 'sandbox-ab-cookie-secret-0123456789abcdef')),
    ),
    // The exposure receipt travels back with the checkout (X-Ab-Receipt); the
    // signature is what stops a client from claiming the other variant.
    Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec::class => static fn (): Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec => new Rasuvaeff\Yii3AbTestingWeb\SignedReceiptCodec(
        secret: App\Environment::string(name: 'AB_RECEIPT_SECRET', default: 'sandbox-ab-receipt-secret-0123456789abcdef'),
    ),
    // The package's definition plus a reporter: the middleware catches every
    // exception below it, so without one a 500 was never logged.
    Rasuvaeff\Yii3ApiProblem\ProblemDetailsMiddleware::class => static fn (
        Rasuvaeff\Yii3ApiProblem\ProblemDetailsResponseFactoryInterface $factory,
        Rasuvaeff\Yii3ApiProblem\DefaultExceptionMapper $mapper,
        LoggerInterface $logger,
    ): Rasuvaeff\Yii3ApiProblem\ProblemDetailsMiddleware => new Rasuvaeff\Yii3ApiProblem\ProblemDetailsMiddleware(
        responseFactory: $factory,
        exceptionMapper: $params['rasuvaeff/yii3-api-problem']['use_default_mapper'] ? $mapper : null,
        debug: $params['rasuvaeff/yii3-api-problem']['debug'],
        throwableReporter: new App\Infrastructure\Shared\Http\ServerErrorReporter(
            logger: $logger,
            mapper: $params['rasuvaeff/yii3-api-problem']['use_default_mapper'] ? $mapper : null,
        ),
    ),
    // Receipts live on the local disk under runtime/files, shared by the app
    // (downloads) and the worker (writes) through the mounted directory.
    Rasuvaeff\Yii3Filestorage\Store\StoreInterface::class => static function (Psr\Http\Message\StreamFactoryInterface $streams): Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore {
        $root = dirname(__DIR__, 3) . '/runtime/files';
        if (!is_dir($root)) {
            mkdir($root, 0o775, true);
        }

        return new Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore(name: 'local', rootPath: $root, streamFactory: $streams);
    },
    Yiisoft\Auth\IdentityRepositoryInterface::class => App\Infrastructure\Operations\Http\NoIdentityRepository::class,
    App\Infrastructure\Operations\Http\CaptchaGate::class => [
        '__construct()' => ['enabled' => (bool) ($params['app']['captcha_enabled'] ?? false)],
    ],
    App\Endpoint\Rest\Operations\ShowCaptcha\Action::class => [
        '__construct()' => ['enabled' => (bool) ($params['app']['captcha_enabled'] ?? false)],
    ],
    // Swagger UI over the generated document (/openapi.json): the same array
    // the checkout is validated against.
    Yiisoft\Swagger\Action\SwaggerUi::class => [
        '__construct()' => ['params' => (array) ($params['yiisoft/yii-swagger']['ui-params'] ?? [])],
        'withJsonUrl()' => ['/openapi.json'],
    ],
    // MCP audit rows credit the identity, not the anonymous connection.
    Rasuvaeff\Yii3McpAuditLogBridge\AuditActorResolverInterface::class => Rasuvaeff\Yii3McpAuditLogBridge\IdentityAuditActorResolver::class,
    PermissionMap::class => static fn (): PermissionMap => PermissionMap::fromToolClasses([
        App\Endpoint\Mcp\Assistant\ShopTools::class,
    ]),
    // FlagProvider and SettingsProvider are bound by yii3-feature-flags-db and
    // yii3-settings-db, the stores the admin pages write. An application
    // binding here would win silently: the admin page would write the table
    // while checkout kept reading config.
    // The result Processor's publisher is the fan-out, not the payment
    // consumer PublisherInterface is bound to.
    App\Endpoint\Console\Ordering\ResultSubscribersCommand::class => [
        '__construct()' => [
            'publisher' => Reference::to(App\Infrastructure\Shared\Outbox\ResultFanOut::class),
        ],
    ],
    HealthChecker::class => [
        '__construct()' => [
            'checks' => [
                Reference::to(App\Infrastructure\Operations\Health\DatabaseHealthCheck::class),
                Reference::to(App\Infrastructure\Payments\Health\PaymentMockHealthCheck::class),
                Reference::to(App\Infrastructure\Operations\Health\RedisHealthCheck::class),
            ],
        ],
    ],
    \Predis\ClientInterface::class => static fn (): \Predis\Client => new \Predis\Client(
        App\Environment::string(name: 'REDIS_URL', default: 'redis://redis:6379'),
    ),
    // The concrete client, for the callers of executeRaw(): it is a real
    // method there and only a magic one on the interface.
    \Predis\Client::class => Reference::to(\Predis\ClientInterface::class),
    CentrifugoClient::class => static fn (ClientInterface $client, RequestFactoryInterface $requestFactory, \Psr\Http\Message\StreamFactoryInterface $streamFactory): CentrifugoClient => new CentrifugoClient(
        httpClient: $client,
        requestFactory: $requestFactory,
        streamFactory: $streamFactory,
        apiUrl: App\Environment::string(name: 'CENTRIFUGO_API_URL', default: 'http://centrifugo:9000'),
        apiKey: App\Environment::string(name: 'CENTRIFUGO_API_KEY', default: 'sandbox-centrifugo-key'),
    ),
    CentrifugoClientInterface::class => CentrifugoClient::class,
    // Its own bounded client: the default discovery hands back Guzzle with no
    // timeout, and a stalled ClickHouse would then hang the single-process
    // built-in server (every UI page) and the worker loop alike.
    Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory::class => [
        '__construct()' => [
            'config' => Reference::to(Rasuvaeff\ClickHouseToolkit\ClickHouseConfig::class),
            'httpClient' => new Client(['timeout' => 3, 'connect_timeout' => 1, 'http_errors' => false]),
        ],
    ],
    // The flags of params, seeded once into the flag table by a data
    // migration. Only that migration asks for it: FlagProvider itself is
    // yii3-feature-flags-db's.
    Rasuvaeff\Yii3FeatureFlags\ConfigFlagProvider::class => static fn (): Rasuvaeff\Yii3FeatureFlags\ConfigFlagProvider => new Rasuvaeff\Yii3FeatureFlags\ConfigFlagProvider(
        flags: (array) ($params['rasuvaeff/yii3-feature-flags']['flags'] ?? []),
    ),
    // One contract for the checkout action, the contract page and the
    // smokes: the document generated from the actions' OpenAPI attributes.
    // yii-swagger configures SwaggerService for the web group only; the
    // console (worker, smokes) needs the same options, so they are applied here.
    App\Endpoint\Rest\Shared\Swagger\OpenApiDocument::class => static fn (Yiisoft\Swagger\Service\SwaggerService $swagger): App\Endpoint\Rest\Shared\Swagger\OpenApiDocument => new App\Endpoint\Rest\Shared\Swagger\OpenApiDocument(
        swagger: $swagger->withOptions(options: (array) ($params['yiisoft/yii-swagger']['open-api-options'] ?? [])),
        sourceDirectory: dirname(__DIR__, 3) . '/src/Endpoint/Rest',
        cacheFile: dirname(__DIR__, 3) . '/runtime/cache/openapi-document.php',
    ),
    Rasuvaeff\OpenApiContract\Contract::class => static fn (App\Endpoint\Rest\Shared\Swagger\OpenApiDocument $document): Rasuvaeff\OpenApiContract\Contract => Rasuvaeff\OpenApiContract\Contract::fromArray(document: $document->toArray()),
    // The result subscribers serve the `order.paid` / `order.payment_failed`
    // integration events. Which of them run is named explicitly, never
    // inferred from what happens to be reachable: a subscriber skipped
    // silently would turn at-least-once into maybe. The default is the
    // realtime projection alone; the `ui` worker adds webhooks and receipts,
    // `make ui-full` adds ClickHouse.
    App\Infrastructure\Shared\Outbox\ResultFanOut::class => static function (
        App\Infrastructure\Ordering\Realtime\PushOrderResultToBrowser $centrifugo,
        Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher $webhooks,
        App\Infrastructure\Payments\Receipt\StoreReceiptOnOrderPaid $receipts,
        App\Infrastructure\Analytics\ClickHouse\ExportOrderResultToClickHouse $clickhouse,
        App\Application\Shared\Inbox\Inbox $inbox,
    ): App\Infrastructure\Shared\Outbox\ResultFanOut {
        $subscribers = ['centrifugo' => $centrifugo, 'webhooks' => $webhooks, 'receipts' => $receipts, 'clickhouse' => $clickhouse];
        $sinks = [];
        foreach (array_filter(array_map('trim', explode(',', App\Environment::string(name: 'RESULT_SINKS', default: 'centrifugo')))) as $name) {
            // Each sink behind the inbox under its own name: when one sink
            // fails and the message is retried for all, the ones that already
            // delivered are not run again.
            $sinks[$name] = new App\Infrastructure\Shared\Outbox\IdempotentConsumer(
                name: $name,
                consumer: $subscribers[$name] ?? throw new \InvalidArgumentException('Unknown result sink "' . $name . '"'),
                inbox: $inbox,
            );
        }

        return new App\Infrastructure\Shared\Outbox\ResultFanOut(sinks: $sinks);
    },
    Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher::class => static fn (
        ClientInterface $httpClient,
        ClockInterface $clock,
        ConnectionInterface $db,
    ): Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher => new Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher(
        dispatcher: new App\Infrastructure\Ordering\Webhook\HttpWebhookDispatcher(
            client: $httpClient,
            factory: new HttpFactory(),
            signer: new Rasuvaeff\Yii3Webhooks\HmacSha256Signer(),
            clock: $clock,
        ),
        endpointProvider: new Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider(array_fill_keys(
            [App\Application\Ordering\Integration\OrderPaid::type(), App\Application\Ordering\Integration\OrderPaymentFailed::type()],
            [new Rasuvaeff\Yii3Webhooks\WebhookEndpoint(
                url: App\Environment::string(name: 'WEBHOOK_MOCK_URL', default: 'http://webhook-mock:8082/webhook'),
                secret: App\Environment::string(name: 'WEBHOOK_SECRET', default: 'sandbox-webhook-secret'),
                allowPrivateNetwork: true,
            )],
        )),
        deliveryStorage: new Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage($db),
    ),
    App\Infrastructure\Payments\Health\PaymentMockHealthCheck::class => static function (ClientInterface $client, RequestFactoryInterface $requestFactory) use ($params): App\Infrastructure\Payments\Health\PaymentMockHealthCheck {
        return new App\Infrastructure\Payments\Health\PaymentMockHealthCheck(
            client: $client,
            requestFactory: $requestFactory,
            endpoint: (string) ($params['app']['payment_mock_health_url'] ?? 'http://payment-mock:8081/health'),
        );
    },
    ClockInterface::class => static fn (): ClockInterface => new App\Infrastructure\Shared\Clock\SystemClock(),
    Outbox::class => static fn (StorageInterface $storage, ClockInterface $clock): Outbox => new Outbox(
        storage: $storage,
        clock: $clock,
    ),
    WorkflowTransaction::class => static fn (ConnectionInterface $db): WorkflowTransaction => new WorkflowTransaction(db: $db),
    App\Application\Operations\GetOperatorPanel\GetOperatorPanelHandler::class => [
        '__construct()' => [
            'settingDefinitions' => (array) ($params['rasuvaeff/yii3-settings']['definitions'] ?? []),
            'sensitiveKeys' => (array) ($params['rasuvaeff/yii3-audit-log']['sensitiveKeys'] ?? []),
        ],
    ],
    // The reports the make targets write under build/ (the tests page).
    App\Application\Operations\GetTestReport\TestReports::class => static fn (): App\Infrastructure\Operations\TestReports\FileTestReports => new App\Infrastructure\Operations\TestReports\FileTestReports(
        buildDirectory: dirname(__DIR__, 3) . '/build',
    ),
    ClientInterface::class => static fn (TracerInterface $tracer, Settings $settings) => new HttpClientSpanDecorator(
        client: new Client([
            'timeout' => $settings->int('payment.gateway_timeout_ms') / 1000,
            'connect_timeout' => $settings->int('payment.gateway_connect_timeout_ms') / 1000,
            'http_errors' => false,
        ]),
        tracer: $tracer,
        propagate: false,
    ),
    Rasuvaeff\Payments\PaymentGatewayInterface::class => static function (ClientInterface $client, ClockInterface $clock) use ($params): App\Infrastructure\Payments\Gateway\HttpPaymentGateway {
        return new App\Infrastructure\Payments\Gateway\HttpPaymentGateway(
            client: $client,
            baseUrl: (string) $params['app']['payment_gateway_url'],
            clock: $clock,
        );
    },
    App\Infrastructure\Payments\Gateway\ResilientPaymentGateway::class => static fn (
        Rasuvaeff\Payments\PaymentGatewayInterface $gateway,
        CompiledPipeline $resilience,
        Settings $settings,
    ): App\Infrastructure\Payments\Gateway\ResilientPaymentGateway => new App\Infrastructure\Payments\Gateway\ResilientPaymentGateway(
        gateway: $gateway,
        resilience: $resilience,
        // Reconciliation retries on its own, outside the charge's
        // breaker: see ResilientPaymentGateway.
        reconciliation: Pipeline::for('payment-reconciliation')
            ->retry(Retry::exponential(maxAttempts: $gatewayAttempts, baseMs: 25, multiplier: 2.0, capMs: 250)->retryOn(\RuntimeException::class))
            ->build(),
        // Room for every in-pipeline attempt at the configured gateway timeout
        // plus the retry pauses, so the deadline ends retries, not the first
        // slow answer.
        deadlineSeconds: $gatewayAttempts * $settings->int('payment.gateway_timeout_ms') / 1000 + 1.0,
    ),
    // The payment worker consumes `payment.requested` through Payments' door.
    PublisherInterface::class => static fn (
        App\Infrastructure\Payments\Outbox\PaymentRequestedConsumer $payments,
        App\Infrastructure\Payments\Outbox\PaymentReconciliationConsumer $reconciliations,
    ): App\Infrastructure\Shared\Outbox\RoutingPublisher => new App\Infrastructure\Shared\Outbox\RoutingPublisher(consumers: [
        App\Application\Ordering\Integration\PaymentRequested::type() => $payments,
        App\Application\Payments\Integration\PaymentReconciliationRequested::type() => $reconciliations,
    ]),
    // The breaker and the bulkhead are services of their own, so the pipeline
    // and the read-only /api/v1/resilience endpoint share one configuration;
    // their state lives in Redis, which both read.
    BreakerConfig::class => static fn (): BreakerConfig => new BreakerConfig(
        name: 'payment-gateway',
        failureThreshold: Ratio::of(
            failures: 2,
            window: 5,
            within: Duration::seconds(60),
        ),
        cooldown: Duration::seconds(15),
        successThreshold: 1,
        // A ContextException is a RuntimeException too, but it describes the
        // worker's budget, not the gateway: counted, one slow-start budget
        // would open the circuit for every payment.
        isFailure: Rasuvaeff\Resilience\ContextOutcome::ignoring(
            static fn (\Throwable $exception): bool => $exception instanceof \RuntimeException,
        ),
    ),
    CircuitBreaker::class => static fn (BreakerConfig $config, \Predis\ClientInterface $redis): CircuitBreaker => new CircuitBreaker(
        config: $config,
        storage: new CircuitRedisStorage(new CircuitRedisScriptRunner($redis)),
        clock: new BreakerClock(),
    ),
    SharedBulkhead::class => static fn (\Predis\ClientInterface $redis): SharedBulkhead => new SharedBulkhead(
        name: 'payment-gateway',
        maxConcurrent: 4,
        store: new RedisBulkheadStore(new BulkheadRedisScriptRunner($redis)),
        lease: Duration::seconds(10),
        maxWait: Duration::zero(),
    ),
    CompiledPipeline::class => static function (CircuitBreaker $breaker, SharedBulkhead $bulkhead) use ($gatewayAttempts): CompiledPipeline {
        return Pipeline::for('payment-gateway')
            ->bulkhead($bulkhead)
            // Same class the breaker counts: a request the gateway rejected
            // (PaymentRequestRejectedException) is not retried.
            ->retry(Retry::exponential(maxAttempts: $gatewayAttempts, baseMs: 25, multiplier: 2.0, capMs: 250)->retryOn(\RuntimeException::class))
            ->circuitBreaker($breaker)
            ->build();
    },
    RedMetricsMiddleware::class => static function (MetricRegistry $registry) use ($params): RedMetricsMiddleware {
        $config = $params['rasuvaeff/yii3-metrics']['red'] ?? [];

        return new RedMetricsMiddleware(
            registry: $registry,
            durationBuckets: (array) ($config['duration_buckets'] ?? []),
            excludedPaths: (array) ($config['excluded_paths'] ?? []),
        );
    },
    // One instance for the Processor and PaymentRequestedConsumer: the
    // consumer fails the order on the attempt the Processor will not repeat.
    // The delay defaults to 0, which the simulate scripts rely on: they run the
    // worker by hand, back to back. The `ui` worker sets it above the breaker
    // cooldown, so a payment queued behind an outage outlives the open circuit.
    RetryPolicy::class => static fn (): RetryPolicy => new RetryPolicy(
        maxAttempts: 3,
        delaySeconds: App\Environment::int(name: 'OUTBOX_RETRY_DELAY_SECONDS', default: 0),
    ),
    Processor::class => static fn (StorageInterface $storage, PublisherInterface $publisher, ClockInterface $clock, RetryPolicy $retryPolicy): Processor => new Processor(
        storage: $storage,
        publisher: $publisher,
        retryPolicy: $retryPolicy,
        clock: $clock,
        // Every type the payment worker routes, and only those: the result
        // fan-out and the A/B export claim disjoint types.
        types: [App\Application\Ordering\Integration\PaymentRequested::type(), App\Application\Payments\Integration\PaymentReconciliationRequested::type()],
    ),
];
