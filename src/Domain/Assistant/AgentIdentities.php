<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * The two agents the demo puts side by side: one holding the support role
 * (MCP_USER_ID), one holding nothing. They must differ, or the page would show
 * the same agent twice under two labels.
 */
final readonly class AgentIdentities
{
    public const string LIMITED = 'limited-user';

    /** @var non-empty-string */
    public string $permitted;

    public function __construct(string $permitted)
    {
        if ($permitted === '' || $permitted === self::LIMITED) {
            throw new \InvalidArgumentException(message: 'MCP_USER_ID must be set and differ from "' . self::LIMITED . '", the identity shown without permissions');
        }
        $this->permitted = $permitted;
    }

    /**
     * @return list<non-empty-string> the permitted agent first
     */
    public function all(): array
    {
        return [$this->permitted, self::LIMITED];
    }
}
