<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Application\Shared\Integration\IntegrationEvent;
use App\Endpoint\Mcp\Assistant\ToolContainer;
use App\Endpoint\Rest\Shared\ServeOpenApiDocument\Action;
use App\Infrastructure\Shared\Clock\SystemClock;
use App\Tests\Support\IntegrationEvents;
use App\Tests\Support\SourceClasses;
use OpenApi\Attributes\Get;
use OpenApi\Attributes\Post;
use Psr\Container\ContainerInterface;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * The architecture's rules that deptrac cannot see, checked in code. Each
 * one is written down in AGENTS.md; a failure names the class that broke it.
 */
#[Test]
#[CoversNothing]
final class ArchitectureTest
{
    /** A use case is one handler with one handle(), and it holds no state. */
    public function everyHandlerHasOneHandleAndNothingElse(): void
    {
        $offenders = [];
        foreach ($this->classesEndingWith(suffix: 'Handler', namespace: 'App\\Application\\') as $class) {
            $reflection = new \ReflectionClass($class);
            $public = array_values(array_filter(
                array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)),
                static fn (string $name): bool => $name !== '__construct',
            ));
            if ($public !== ['handle'] || !$reflection->isFinal() || !$reflection->isReadOnly()) {
                $offenders[] = $class . ' (' . implode(separator: ', ', array: $public) . ')';
            }
        }

        Assert::same($offenders, []);
    }

    /** Commands and queries are immutable values: final readonly, public readonly fields only. */
    public function commandsAndQueriesAreValues(): void
    {
        $offenders = [];
        foreach ([...$this->classesEndingWith(suffix: 'Command', namespace: 'App\\Application\\'), ...$this->classesEndingWith(suffix: 'Query', namespace: 'App\\Application\\')] as $class) {
            $reflection = new \ReflectionClass($class);
            $methods = array_filter($reflection->getMethods(), static fn (\ReflectionMethod $method): bool => $method->getName() !== '__construct');
            if (!$reflection->isFinal() || !$reflection->isReadOnly() || $methods !== []) {
                $offenders[] = $class;
            }
        }

        Assert::same($offenders, []);
    }

    /** The OpenAPI document is generated from the actions: an undocumented action is not in the contract. */
    public function everyRestActionIsDocumented(): void
    {
        $offenders = [];
        foreach (SourceClasses::all() as $class => $source) {
            // The document itself is not an operation of the document.
            if (!str_starts_with($class, needle: 'App\\Endpoint\\Rest\\') || !str_ends_with($class, needle: '\\Action') || $class === Action::class) {
                continue;
            }
            $invoke = new \ReflectionMethod($class, method: '__invoke');
            if ($invoke->getAttributes(Get::class) === [] && $invoke->getAttributes(Post::class) === []) {
                $offenders[] = $class;
            }
        }

        Assert::same($offenders, []);
    }

    /** Time comes from the clock: nothing in src/ reads the system's own. */
    public function nothingReadsTheSystemClock(): void
    {
        $offenders = [];
        foreach (SourceClasses::all() as $class => $source) {
            if ($class === SystemClock::class || str_starts_with($class, needle: 'App\\Infrastructure\\Migration\\')) {
                continue;
            }
            if (preg_match(pattern: '/new \\\\?DateTime(Immutable)?\(\s*\)|\btime\(\)|\bdate\(|CURRENT_TIMESTAMP|\bmicrotime\(/', subject: $source) === 1) {
                $offenders[] = $class;
            }
        }

        Assert::same($offenders, []);
    }

    /** No service locator: a class names what it needs. ToolContainer is what yii3-mcp requires. */
    public function noClassTakesTheContainer(): void
    {
        $offenders = [];
        foreach (array_keys(SourceClasses::all()) as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInterface() || $reflection->isEnum() || $class === ToolContainer::class) {
                continue;
            }
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getType() instanceof \ReflectionNamedType && $parameter->getType()->getName() === ContainerInterface::class) {
                    $offenders[] = $class;
                }
            }
        }

        Assert::same($offenders, []);
    }

    /** The application's convention: no #[\Override] anywhere in it. */
    public function noOverrideAttribute(): void
    {
        $offenders = array_keys(array_filter(SourceClasses::all(), static fn (string $source): bool => str_contains($source, needle: '#[\\Override]')));

        Assert::same($offenders, []);
    }

    /** An integration event no registry knows could be published but never parsed. */
    public function everyIntegrationEventIsRegistered(): void
    {
        $declared = [];
        foreach (array_keys(SourceClasses::all()) as $class) {
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInterface() && $reflection->implementsInterface(IntegrationEvent::class)) {
                $declared[] = $class;
            }
        }
        $registered = IntegrationEvents::registry()->events();
        sort($declared);
        sort($registered);

        Assert::same($declared, $registered);
    }

    /**
     * @return list<class-string>
     */
    private function classesEndingWith(string $suffix, string $namespace): array
    {
        return array_values(array_filter(
            array_keys(SourceClasses::all()),
            static fn (string $class): bool => str_starts_with($class, $namespace) && str_ends_with($class, $suffix) && class_exists($class),
        ));
    }
}
