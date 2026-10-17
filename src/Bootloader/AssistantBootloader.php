<?php

declare(strict_types=1);

namespace App\Bootloader;

use App\Application\Assistant\GetTranscript\AgentConversation;
use App\Application\Assistant\GetTranscript\ToolCallAudit;
use App\Domain\Assistant\Access\Role;
use App\Domain\Assistant\AgentIdentities;
use App\Endpoint\Mcp\Assistant\InProcessAgentConversation;
use App\Environment;
use App\Infrastructure\Assistant\Audit\DbToolCallAudit;
use App\Infrastructure\Assistant\Rbac\InMemoryAssignments;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3McpRbacBridge\IdentitySourceInterface;
use Rasuvaeff\Yii3McpRbacBridge\StaticIdentitySource;
use Yiisoft\Access\AccessCheckerInterface;
use Yiisoft\Di\ServiceProviderInterface;
use Yiisoft\Rbac\Assignment;
use Yiisoft\Rbac\Manager;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Php\ItemsStorage;

/**
 * The AI agent's access: yiisoft/rbac with the role and permissions of the
 * domain (config/common/rbac-items.php), the support role assigned to the
 * one permitted identity (MCP_USER_ID). The MCP endpoint, the agent page and
 * the RBAC smoke all ask this one manager.
 */
final readonly class AssistantBootloader implements ServiceProviderInterface
{
    public function getDefinitions(): array
    {
        return [
            AgentIdentities::class => static fn (): AgentIdentities => new AgentIdentities(
                permitted: Environment::string(name: 'MCP_USER_ID', default: 'demo-user'),
            ),
            ManagerInterface::class => static function (AgentIdentities $identities, ClockInterface $clock): Manager {
                $assignments = new InMemoryAssignments();
                $assignments->add(new Assignment(userId: $identities->permitted, itemName: Role::SupportAgent->value, createdAt: $clock->now()->getTimestamp()));

                return new Manager(
                    itemsStorage: new ItemsStorage(filePath: dirname(path: __DIR__, levels: 2) . '/config/common/rbac-items.php'),
                    assignmentsStorage: $assignments,
                );
            },
            AccessCheckerInterface::class => ManagerInterface::class,
            // /mcp runs as the permitted identity.
            IdentitySourceInterface::class => static fn (AgentIdentities $identities): StaticIdentitySource => new StaticIdentitySource(id: $identities->permitted),
            AgentConversation::class => InProcessAgentConversation::class,
            ToolCallAudit::class => DbToolCallAudit::class,
        ];
    }

    public function getExtensions(): array
    {
        return [];
    }
}
