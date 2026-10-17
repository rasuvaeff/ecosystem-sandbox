<?php

declare(strict_types=1);

use App\Domain\Ordering\OrderStatus;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

return [
    // One experiment on the shop page: the pay button. Assignment is a hash of
    // the visitor's ab_id cookie, exposures and conversions go to the outbox,
    // and `make ui-full` exports them to ClickHouse.
    'rasuvaeff/yii3-ab-testing' => [
        'experiments' => [
            'checkout-button' => [
                'enabled' => true,
                'salt' => 'checkout-button-v1',
                'fallbackVariant' => 'control',
                'variants' => ['control' => 50, 'green' => 50],
            ],
        ],
    ],
    'rasuvaeff/yii3-ab-testing-outbox' => [
        'aggregateIdSecret' => App\Environment::string(name: 'AB_AGGREGATE_SECRET', default: 'sandbox-ab-aggregate-secret'),
    ],
    // PostgreSQL: yiisoft/db-migration's migrate:up over every package's
    // migration namespace plus the application's own; the history table
    // records what ran. The packages' migrations take their table-name
    // objects from DI.
    'yiisoft/db-migration' => [
        'newMigrationNamespace' => 'App\\Infrastructure\\Migration',
        'sourceNamespaces' => [
            'Rasuvaeff\\Yii3IdempotencyDb\\Migration',
            'Rasuvaeff\\Yii3WorkflowDb\\Migration',
            'Rasuvaeff\\Yii3AuditLogDb\\Migration',
            'Rasuvaeff\\Yii3OutboxDb\\Migration',
            'Rasuvaeff\\Yii3WebhooksDb\\Migration',
            'Rasuvaeff\\Yii3UtmDb\\Migration',
            'Rasuvaeff\\Yii3FilestorageDb\\Migration',
            'Rasuvaeff\\Yii3FilestorageAttachments\\Migration',
            'Rasuvaeff\\Yii3FeatureFlagsDb\\Migration',
            'Rasuvaeff\\Yii3SettingsDb\\Migration',
            'App\\Infrastructure\\Migration',
        ],
    ],
    // ClickHouse: yii3-clickhouse-toolkit's clickhouse:migrations:migrate over
    // the application's .sql files. Its own bookkeeping table, apart from the
    // one yii3-ab-testing-clickhouse's SchemaMigrations keeps.
    'rasuvaeff/yii3-clickhouse-toolkit' => [
        'host' => App\Environment::string(name: 'CLICKHOUSE_HOST', default: 'clickhouse'),
        'port' => App\Environment::int(name: 'CLICKHOUSE_PORT', default: 8123),
        'database' => App\Environment::string(name: 'CLICKHOUSE_DB', default: 'sandbox'),
        'username' => App\Environment::string(name: 'CLICKHOUSE_USER', default: 'sandbox'),
        'password' => App\Environment::string(name: 'CLICKHOUSE_PASSWORD', default: 'sandbox'),
        'migrationsNamespace' => 'App\\Infrastructure\\Migration\\ClickHouse',
        'migrationsTable' => 'sandbox_clickhouse_migrations',
        'migrationPlaceholders' => [
            'order_results_table' => App\Infrastructure\Analytics\ClickHouse\ExportOrderResultToClickHouse::TABLE,
        ],
    ],
    'rasuvaeff/yii3-outbox-clickhouse' => [
        'routes' => Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes::map(),
    ],
    // Payment receipts: plain text, small, shown inline. The signing key turns
    // a file id into a time-limited download URL; rotate it by adding a key.
    'rasuvaeff/yii3-filestorage' => [
        'policies' => [
            'receipts' => ['allowedMimeTypes' => ['text/plain'], 'maxBytes' => 65_536],
        ],
        'delivery' => [
            // A receipt is saved, not opened in a browser tab.
            'receipts' => ['allowDirectPublicUrl' => false, 'forceDownload' => true],
        ],
    ],
    'rasuvaeff/yii3-filestorage-web' => [
        'signingKeys' => [
            'active' => 'sandbox-1',
            'keys' => ['sandbox-1' => App\Environment::string(name: 'FILES_SIGNING_KEY', default: 'sandbox-files-signing-key-0123456789abcdef')],
        ],
    ],
    // The packages' admin pages: a Bootstrap shell with the way back, and the
    // flag edit view with its unguarded $csrf fixed (see the file).
    'rasuvaeff/yii3-feature-flags-ui' => [
        'layout' => dirname(__DIR__, 2) . '/resources/admin/layout.php',
        'views' => ['edit' => dirname(__DIR__, 2) . '/resources/admin/flag-edit.php'],
    ],
    'rasuvaeff/yii3-settings-ui' => [
        'layout' => dirname(__DIR__, 2) . '/resources/admin/layout.php',
    ],
    // Swagger UI publishes its assets under public/assets.
    // The OpenAPI document is generated from the attributes under
    // src/Endpoint/Rest (App\Endpoint\Rest\Shared\Swagger\OpenApiDocument).
    // The processors are swagger-php's own, minus the two that write what the
    // attributes did not say: DocBlockDescriptions and AugmentProperties copy
    // class docblocks into descriptions and add a `type` beside every `const`.
    // Every property is spelled out in its attribute instead. Validation is
    // off: swagger-php 4 rejects OpenAPI 3.1 type lists (`[array, 'null']`)
    // under `items`. OperationId keeps the ids the attributes give, which the
    // contract page and the smokes address.
    'yiisoft/yii-swagger' => [
        'annotation-paths' => [App\Endpoint\Rest\Shared\Swagger\OpenApiDocument::ANNOTATION_PATH],
        'open-api-options' => [
            'validate' => false,
            'version' => '3.1.0',
            'processors' => [
                new OpenApi\Processors\MergeIntoOpenApi(),
                new OpenApi\Processors\MergeIntoComponents(),
                new OpenApi\Processors\ExpandClasses(),
                new OpenApi\Processors\ExpandInterfaces(),
                new OpenApi\Processors\ExpandTraits(),
                new OpenApi\Processors\ExpandEnums(),
                new OpenApi\Processors\AugmentSchemas(),
                new OpenApi\Processors\AugmentRequestBody(),
                new OpenApi\Processors\BuildPaths(),
                new OpenApi\Processors\AugmentParameters(),
                new OpenApi\Processors\AugmentRefs(),
                new OpenApi\Processors\MergeJsonContent(),
                new OpenApi\Processors\MergeXmlContent(),
                new OpenApi\Processors\OperationId(),
                new OpenApi\Processors\CleanUnmerged(),
                new OpenApi\Processors\PathFilter(),
                new OpenApi\Processors\CleanUnusedComponents(),
                new OpenApi\Processors\AugmentTags(),
            ],
        ],
    ],
    'yiisoft/aliases' => [
        'aliases' => [
            '@root' => dirname(__DIR__, 2),
            '@src' => '@root/src',
            '@vendor' => '@root/vendor',
            '@public' => '@root/public',
            '@assets' => '@public/assets',
            '@assetsUrl' => '/assets',
            '@views' => '@root/resources/views',
        ],
    ],
    // The web UI's pages are rendered on the server so each head carries its
    // own metadata (rasuvaeff/yii3-seo); these are the site-wide defaults the
    // page's Metadata is merged into. A sandbox is never indexed until it is
    // deployed on purpose.
    'yiisoft/yii-view-renderer' => [
        'injections' => [Rasuvaeff\Yii3Seo\SeoInjection::class],
    ],
    'rasuvaeff/yii3-seo' => [
        'defaults' => new Rasuvaeff\Yii3Seo\MetadataDefaults(
            metadataBase: App\Environment::string(name: 'APP_PUBLIC_URL', default: 'http://127.0.0.1:8080'),
            title: Rasuvaeff\Yii3Seo\Title::template('%s | rasuvaeff/* sandbox', default: 'rasuvaeff/* sandbox'),
            applicationName: 'rasuvaeff/* sandbox',
            openGraph: new Rasuvaeff\Yii3Seo\OpenGraph(siteName: 'rasuvaeff/* sandbox', locale: 'en_US'),
        ),
        'robotsTxt' => [
            'indexable' => App\Environment::bool(name: 'APP_INDEXABLE', default: false),
        ],
    ],
    'rasuvaeff/yii3-telemetry-otel' => [
        'enabled' => true,
        'service_name' => App\Environment::string(name: 'OTEL_SERVICE_NAME', default: 'rasuvaeff-sandbox'),
        'endpoint' => App\Environment::string(name: 'OTEL_EXPORTER_OTLP_ENDPOINT', default: 'http://jaeger:4318'),
        'batch' => false,
        'excluded_paths' => ['/health', '/metrics'],
    ],
    'rasuvaeff/yii3-metrics-prometheus' => [
        'storage' => 'pdo',
        'storage_options' => [
            'dsn' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                App\Environment::string(name: 'DB_HOST', default: 'postgres'),
                App\Environment::string(name: 'DB_PORT', default: '5432'),
                App\Environment::string(name: 'DB_NAME', default: 'sandbox'),
            ),
            'username' => App\Environment::string(name: 'DB_USER', default: 'sandbox'),
            'password' => App\Environment::string(name: 'DB_PASSWORD', default: 'sandbox'),
            'prefix' => 'sandbox_prometheus_',
        ],
        'namespace' => 'sandbox',
    ],
    'rasuvaeff/yii3-metrics' => [
        'red' => [
            'excluded_paths' => ['/health', '/metrics'],
        ],
    ],
    'rasuvaeff/yii3-correlation-id' => [
        'headerName' => 'X-Request-ID',
        'attributeName' => 'correlationId',
        'acceptIncoming' => false,
        'validationPattern' => CorrelationIdMiddleware::UUID_V4_PATTERN,
        'maxLength' => 128,
        'contextKey' => 'requestId',
    ],
    'rasuvaeff/yii3-maintenance-mode' => [
        'enabled' => App\Environment::bool(name: 'MAINTENANCE_ENABLED', default: false),
        'retryAfter' => App\Environment::int(name: 'MAINTENANCE_RETRY_AFTER', default: 120),
        'allowedIps' => [],
        'bypassTokenHash' => hash('sha256', App\Environment::string(name: 'MAINTENANCE_BYPASS_TOKEN', default: 'sandbox-maintenance-bypass')),
        'access' => [
            'excludedPathPrefixes' => ['/live', '/ready', '/health', '/metrics'],
            'bypassHeader' => 'X-Maintenance-Bypass',
            'bypassQueryParameter' => 'bypass',
            'allowQueryParameter' => false,
        ],
    ],
    'rasuvaeff/yii3-idempotency' => [
        'callerAttribute' => false,
        'scope' => 'checkout-demo',
    ],
    'rasuvaeff/yii3-api-problem' => [
        'debug' => false,
        'use_default_mapper' => true,
        'exception_map' => [],
    ],
    'rasuvaeff/yii3-feature-flags' => [
        'flags' => [
            'payments.enabled' => [
                'enabled' => App\Environment::bool(name: 'PAYMENTS_ENABLED', default: true),
                'killSwitch' => false,
            ],
        ],
        'strictMode' => true,
    ],
    'rasuvaeff/yii3-settings' => [
        'definitions' => [
            'payment.gateway_timeout_ms' => ['type' => 'int', 'default' => 3000],
            'payment.gateway_connect_timeout_ms' => ['type' => 'int', 'default' => 1000],
            'payment.gateway_mode' => ['type' => 'string', 'default' => 'sandbox'],
        ],
        'values' => [
            'payment.gateway_timeout_ms' => App\Environment::int(name: 'PAYMENT_GATEWAY_TIMEOUT_MS', default: 3000),
            'payment.gateway_connect_timeout_ms' => App\Environment::int(name: 'PAYMENT_GATEWAY_CONNECT_TIMEOUT_MS', default: 1000),
            'payment.gateway_mode' => App\Environment::string(name: 'PAYMENT_GATEWAY_MODE', default: 'sandbox'),
        ],
        'strictMode' => true,
    ],
    // Only the token secret: the sandbox binds its own CentrifugoClient from
    // CENTRIFUGO_API_URL / CENTRIFUGO_API_KEY. The secret signs the browser's
    // connection token, which subscribes it to one order's channel.
    'rasuvaeff/yii3-centrifugo' => [
        'token_hmac_secret' => App\Environment::string(name: 'CENTRIFUGO_TOKEN_SECRET', default: 'sandbox-centrifugo-token-secret-0123456789'),
        'token_ttl' => 600,
    ],
    'rasuvaeff/yii3-webhooks-db' => [
        'deliveryTable' => App\Domain\Shared\TableNames::WEBHOOK_DELIVERIES,
        'nonceTable' => App\Domain\Shared\TableNames::WEBHOOK_NONCES,
    ],
    'rasuvaeff/yii3-utm-db' => [
        'table' => App\Domain\Shared\TableNames::UTM_ATTRIBUTION,
        'retentionDays' => 365,
    ],
    'rasuvaeff/yii3-tenancy' => [
        'unresolved_policy' => 'passthrough',
        'suspended_policy' => 'reject',
        'tenants' => [
            'acme' => ['name' => 'Acme Demo', 'status' => 'active'],
            'globex' => ['name' => 'Globex Demo', 'status' => 'active'],
        ],
    ],
    'rasuvaeff/yii3-mcp' => [
        'server_name' => 'rasuvaeff-sandbox-mcp',
        'server_version' => '1.0.0',
        'tools' => [App\Endpoint\Mcp\Assistant\ShopTools::class],
        'endpoint_secret' => App\Environment::string(name: 'MCP_ENDPOINT_SECRET', default: 'sandbox-mcp-secret'),
        'allowed_hosts' => ['localhost', '127.0.0.1', 'app'],
        'modern_era' => true,
        // The audit trail is outermost, so a refused call is recorded too.
        'interceptors' => [
            Rasuvaeff\Yii3McpAuditLogBridge\AuditTrailInterceptor::class,
            Rasuvaeff\Yii3McpRbacBridge\SessionIdentityInterceptor::class,
            Rasuvaeff\Yii3McpRbacBridge\RbacToolCallInterceptor::class,
            Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor::class,
        ],
        'tool_visibility' => Rasuvaeff\Yii3McpRbacBridge\RbacToolVisibility::class,
    ],
    'rasuvaeff/yii3-idempotency-db' => [
        'table' => App\Domain\Shared\TableNames::IDEMPOTENCY_KEYS,
        'claimTtlSeconds' => 3600,
        'gcDivisor' => 1000,
    ],
    'rasuvaeff/yii3-workflow' => [
        'workflows' => [
            'order' => [
                'type' => 'state_machine',
                'initial' => OrderStatus::Pending,
                'places' => OrderStatus::cases(),
                // Written on OrderWorkflowSubject, never on the aggregate.
                'markingStore' => ['type' => 'enum', 'enum' => OrderStatus::class, 'property' => 'status'],
                // The lifecycle is defined once, in the domain.
                'transitions' => App\Domain\Ordering\OrderTransition::definitions(),
            ],
        ],
    ],
    'rasuvaeff/yii3-feature-flags-db' => ['table' => App\Domain\Shared\TableNames::FEATURE_FLAGS],
    'rasuvaeff/yii3-settings-db' => ['table' => App\Domain\Shared\TableNames::SETTINGS],
    'rasuvaeff/yii3-filestorage-db' => [
        'fileTable' => App\Domain\Shared\TableNames::FILESTORAGE_FILE,
        'blobTable' => App\Domain\Shared\TableNames::FILESTORAGE_BLOB,
        'blobReservationTable' => App\Domain\Shared\TableNames::FILESTORAGE_BLOB_RESERVATION,
    ],
    'rasuvaeff/yii3-filestorage-attachments' => ['attachmentTable' => App\Domain\Shared\TableNames::FILESTORAGE_ATTACHMENT],
    'rasuvaeff/yii3-workflow-db' => ['table' => App\Domain\Shared\TableNames::WORKFLOW_TRANSITIONS],
    'rasuvaeff/yii3-audit-log' => [
        'sensitiveKeys' => ['password', 'secret', 'token', 'api_key', 'credit_card'],
        'skipEmptyChangeSets' => true,
    ],
    'rasuvaeff/yii3-audit-log-db' => ['table' => App\Domain\Shared\TableNames::AUDIT_LOG],
    'rasuvaeff/yii3-outbox-db' => [
        'table' => App\Domain\Shared\TableNames::OUTBOX,
        'require_transaction' => true,
        'skip_locked' => true,
    ],
    // Google's documented reCAPTCHA v2 test keys (they always pass and show a
    // banner); CAPTCHA_ENABLED turns the checkout gate on. RECAPTCHA_VERIFY_URL
    // points the check at the payment mock's stand-in for offline tests.
    'rasuvaeff/yii3-recaptcha' => [
        'siteKeyV2' => App\Environment::string(name: 'RECAPTCHA_SITE_KEY', default: '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'),
        'secretV2' => App\Environment::string(name: 'RECAPTCHA_SECRET', default: '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'),
        'verifyUrl' => App\Environment::string(name: 'RECAPTCHA_VERIFY_URL', default: 'https://www.google.com/recaptcha/api/siteverify'),
    ],
    'app' => [
        'captcha_enabled' => App\Environment::bool(name: 'CAPTCHA_ENABLED', default: false),
        'database' => [
            'host' => App\Environment::string(name: 'DB_HOST', default: 'postgres'),
            'port' => App\Environment::string(name: 'DB_PORT', default: '5432'),
            'name' => App\Environment::string(name: 'DB_NAME', default: 'sandbox'),
            'user' => App\Environment::string(name: 'DB_USER', default: 'sandbox'),
            'password' => App\Environment::string(name: 'DB_PASSWORD', default: 'sandbox'),
        ],
        'payment_gateway_url' => App\Environment::string(name: 'PAYMENT_GATEWAY_URL', default: 'http://payment-mock:8081'),
        'payment_mock_health_url' => App\Environment::string(name: 'PAYMENT_MOCK_HEALTH_URL', default: 'http://payment-mock:8081/health'),
    ],
];
