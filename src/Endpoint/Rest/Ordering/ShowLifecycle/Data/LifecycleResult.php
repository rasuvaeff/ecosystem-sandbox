<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\ShowLifecycle\Data;

use App\Application\Ordering\DescribeLifecycle\LifecycleDescription;

/**
 * The order workflow's places and transitions as the runtime holds them.
 */
final readonly class LifecycleResult implements \JsonSerializable
{
    /**
     * @param list<string> $initial
     * @param list<string> $places
     * @param list<array{name: string, from: list<string>, to: list<string>}> $transitions
     */
    public function __construct(
        public string $name,
        public array $initial,
        public array $places,
        public array $transitions,
    ) {}

    public static function fromDescription(LifecycleDescription $description): self
    {
        return new self(name: $description->name, initial: $description->initial, places: $description->places, transitions: $description->transitions);
    }

    /**
     * @return array{name: string, initial: list<string>, places: list<string>, transitions: list<array{name: string, from: list<string>, to: list<string>}>}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'initial' => $this->initial, 'places' => $this->places, 'transitions' => $this->transitions];
    }
}
