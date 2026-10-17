<?php

declare(strict_types=1);

namespace App\Endpoint\Mcp\Assistant;

use HttpSoft\Message\ResponseFactory;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use Mcp\Server\Session\InMemorySessionStore;
use Rasuvaeff\Yii3AuditLog\AuditLogger;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3McpAuditLogBridge\AuditTrailInterceptor;
use Rasuvaeff\Yii3McpAuditLogBridge\IdentityAuditActorResolver;
use Rasuvaeff\Yii3McpRbacBridge\PermissionMap;
use Rasuvaeff\Yii3McpRbacBridge\RbacToolCallInterceptor;
use Rasuvaeff\Yii3McpRbacBridge\RbacToolVisibility;
use Rasuvaeff\Yii3McpRbacBridge\SessionIdentityInterceptor;
use Rasuvaeff\Yii3McpRbacBridge\StaticIdentitySource;
use Yiisoft\Access\AccessCheckerInterface;

/**
 * An in-process MCP client talking to the sandbox tools as one identity, with
 * the same RBAC wiring the HTTP endpoint uses. The one recipe for assembling
 * that server: the UI's agent page and the RBAC smoke both build it here, so
 * they cannot drift apart.
 */
final readonly class AgentFactory
{
    public function __construct(
        private ShopTools $tools,
        private AccessCheckerInterface $accessChecker,
        private AuditLogger $auditLogger,
    ) {}

    public function as(string $userId): McpTester
    {
        $identity = new StaticIdentitySource(id: $userId);
        $permissions = PermissionMap::fromToolClasses([ShopTools::class]);
        $server = new McpServerFactory(
            container: new ToolContainer([ShopTools::class => $this->tools]),
            sessionStore: new InMemorySessionStore(),
            name: 'sandbox-agent-' . $userId,
            version: '1.0.0',
        )->create(
            toolClasses: [ShopTools::class],
            // Outermost, so a call the RBAC interceptor refuses is recorded
            // as `rejected` too; the event is credited to the identity.
            interceptors: [
                new AuditTrailInterceptor(
                    auditLogger: $this->auditLogger,
                    actorResolver: new IdentityAuditActorResolver(identitySource: $identity),
                ),
                new SessionIdentityInterceptor(identitySource: $identity),
                new RbacToolCallInterceptor(accessChecker: $this->accessChecker, identitySource: $identity, permissions: $permissions),
            ],
            toolVisibility: new RbacToolVisibility(accessChecker: $this->accessChecker, identitySource: $identity, permissions: $permissions),
        );

        return new McpTester(
            server: $server,
            requestFactory: new ServerRequestFactory(),
            responseFactory: new ResponseFactory(),
            streamFactory: new StreamFactory(),
        );
    }
}
