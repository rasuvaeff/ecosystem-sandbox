<?php

declare(strict_types=1);

namespace App\Endpoint\Mcp\Assistant;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The one service an in-process MCP server needs: the tool object, already
 * resolved through the application container.
 */
final readonly class ToolContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class ('Service not found: ' . $id) extends \RuntimeException implements NotFoundExceptionInterface, ContainerExceptionInterface {};
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
