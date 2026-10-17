<?php

declare(strict_types=1);

use App\Endpoint\Mcp\Assistant\ShopTools;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use Mcp\Server\Session\InMemorySessionStore;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Rasuvaeff\Yii3AuditLog\AuditLogger;
use Rasuvaeff\Yii3AuditLog\InMemoryAuditWriter;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3McpAuditLogBridge\AuditTrailInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\MetricsToolCallInterceptor;
use Rasuvaeff\Yii3McpTelemetryBridge\TracingToolCallInterceptor;
use Rasuvaeff\Yii3Metrics\InMemoryMeterProvider;
use Rasuvaeff\Yii3Metrics\MetricRegistry;
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\Tracer;

require dirname(__DIR__) . '/vendor/autoload.php';

final class SandboxContainer implements ContainerInterface
{
    /** @param array<class-string, object> $services */
    public function __construct(private array $services) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class ($id) extends RuntimeException implements NotFoundExceptionInterface {
                public function __construct(string $id)
                {
                    parent::__construct('Service not found: ' . $id);
                }
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}

$clock = new class implements ClockInterface {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@1750000000');
    }
};
$meterProvider = new InMemoryMeterProvider();
$writer = new InMemoryAuditWriter();
// The real tool class from the real container: this smoke is about the
// telemetry and audit bridges, but a fake tool here would also make it the
// only place where the tools' schema is "checked" against nothing.
/** @var ContainerInterface $appContainer */
$appContainer = require __DIR__ . '/support/container.php';
$container = new SandboxContainer([
    ShopTools::class => $appContainer->get(ShopTools::class),
]);
$server = new McpServerFactory(
    container: $container,
    sessionStore: new InMemorySessionStore(),
    name: 'rasuvaeff-sandbox-mcp',
    version: '1.0.0',
)->create(
    toolClasses: [ShopTools::class],
    interceptors: [
        new TracingToolCallInterceptor(new Tracer(new NullTracerProvider())),
        new MetricsToolCallInterceptor(new MetricRegistry($meterProvider)),
        new AuditTrailInterceptor(new AuditLogger(writer: $writer, clock: $clock)),
    ],
);
$tester = new McpTester(
    server: $server,
    requestFactory: new ServerRequestFactory(),
    responseFactory: new ResponseFactory(),
    streamFactory: new StreamFactory(),
);
$tools = $tester->listTools();
$result = $tester->callTool('order.status', ['orderId' => 'mcp-demo']);
$timeline = $tester->callTool('order.timeline', ['orderId' => 'mcp-demo']);

if (count($tools) !== 3 || !str_contains((string) ($result['content'][0]['text'] ?? ''), 'mcp-demo') || count($writer->getEvents()) !== 2) {
    throw new RuntimeException('MCP tool, audit or Streamable HTTP tester path failed');
}
// Reading the real store: an order nobody created is reported as missing
// instead of a hardcoded status.
if (($result['structuredContent']['found'] ?? null) !== false
    || ($result['structuredContent']['status'] ?? null) !== 'unknown') {
    throw new RuntimeException('order.status did not read the real order store');
}

$metric = $meterProvider->value('mcp_tool_calls_total', ['tool' => 'order.status', 'outcome' => 'success']);
if ($metric !== 1.0 || ($timeline['structuredContent']['found'] ?? null) !== false
    || ($timeline['structuredContent']['events'] ?? null) !== []) {
    throw new RuntimeException('MCP telemetry bridge or timeline tool failed: metric=' . var_export($metric, true));
}

echo 'MCP PASS: tools=' . count($tools) . ' calls=2 audit_events=' . count($writer->getEvents()) . " metrics=recorded transport=streamable-http\n";
