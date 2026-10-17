<?php

declare(strict_types=1);

namespace App\Infrastructure\Assistant\Rbac;

use Yiisoft\Rbac\SimpleAssignmentsStorage;

/**
 * Role assignments held in memory, built from configuration on every
 * request: the sandbox has one permitted identity (MCP_USER_ID) and no user
 * store to keep assignments in. yiisoft/rbac ships the behaviour as an
 * abstract base; this is the concrete one.
 */
final class InMemoryAssignments extends SimpleAssignmentsStorage {}
