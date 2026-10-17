<?php

declare(strict_types=1);

use App\Endpoint\Mcp\Assistant\AgentFactory;
use Psr\Container\ContainerInterface;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var ContainerInterface $appContainer real sandbox wiring for the tools */
$appContainer = require __DIR__ . '/support/container.php';
$agents = $appContainer->get(AgentFactory::class);
$permitted = $appContainer->get(App\Domain\Assistant\AgentIdentities::class)->permitted;

$tester = static fn (string $userId): McpTester => $agents->as($userId);

$allowed = $tester($permitted);
$allowedTools = array_column($allowed->listTools(), 'name');
if (!in_array('order.status', $allowedTools, true) || !in_array('payment.gateway_status', $allowedTools, true)) {
    throw new RuntimeException('RBAC allowed user did not see the permitted tools');
}
$statusResult = $allowed->callTool('order.status', ['orderId' => 'rbac-missing-order']);
$statusJson = json_encode($statusResult, JSON_THROW_ON_ERROR);
if (!str_contains($statusJson, '"found":false') || !str_contains($statusJson, 'unknown')) {
    throw new RuntimeException('order.status did not read the real order store: ' . $statusJson);
}

// yii3-mcp-audit-log-bridge: the call above is a row in audit_log, credited to
// the identity rather than the anonymous connection, with its outcome.
$trail = $appContainer->get(App\Infrastructure\Assistant\Audit\DbToolCallAudit::class)->latestFor(userId: $permitted, since: gmdate('Y-m-d H:i:s', time() - 60), limit: 5);
$audited = array_values(array_filter($trail, static fn (App\Application\Assistant\GetTranscript\AuditedCall $row): bool => $row->tool === 'order.status' && ($row->arguments->orderId ?? null) === 'rbac-missing-order'));
if ($audited === [] || $audited[0]->outcome !== 'success') {
    throw new RuntimeException('the MCP call left no audit row credited to ' . $permitted . ': ' . json_encode($trail));
}

$limited = $tester(App\Domain\Assistant\AgentIdentities::LIMITED);
$limitedTools = array_column($limited->listTools(), 'name');
if ($limitedTools !== []) {
    throw new RuntimeException('RBAC visibility did not fail closed for a limited user');
}

$denied = false;

try {
    $limited->callTool('order.status', ['orderId' => 'rbac-demo']);
} catch (Throwable) {
    $denied = true;
}
if (!$denied) {
    throw new RuntimeException('RBAC call interceptor allowed a denied tool');
}

echo "MCP RBAC PASS: allowed={$permitted} visible=" . count($allowedTools)
    . " limited=limited-user visible=" . count($limitedTools) . " denied=fail-closed audit=" . $audited[0]->outcome . "\n";
