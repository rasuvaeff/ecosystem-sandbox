<?php

declare(strict_types=1);

namespace App\Application\Ordering\DescribeLifecycle;

final readonly class LifecycleDescription implements \JsonSerializable
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

    /**
     * @return array{name: string, initial: list<string>, places: list<string>, transitions: list<array{name: string, from: list<string>, to: list<string>}>}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'initial' => $this->initial, 'places' => $this->places, 'transitions' => $this->transitions];
    }
}
