<?php

declare(strict_types=1);

namespace App;

/**
 * The one place the sandbox reads its process environment. Values are read
 * and checked once, at the entry point (src/autoload.php): a malformed number
 * or an unknown APP_ENV fails the start, not the request that first needs it.
 */
final class Environment
{
    public const string DEV = 'dev';
    public const string TEST = 'test';
    public const string PROD = 'prod';
    public const array ENVIRONMENTS = [
        self::DEV,
        self::TEST,
        self::PROD,
    ];

    /** @var non-empty-string|null */
    private static ?string $appEnv = null;

    private static bool $appDebug = false;

    public static function prepare(): void
    {
        $environment = self::raw(name: 'APP_ENV') ?? self::DEV;
        if (!in_array(needle: $environment, haystack: self::ENVIRONMENTS, strict: true)) {
            throw new \RuntimeException(message: sprintf(
                'APP_ENV="%s" is invalid. Valid values are "%s".',
                $environment,
                implode(separator: '", "', array: self::ENVIRONMENTS),
            ));
        }
        self::$appEnv = $environment;
        self::$appDebug = self::bool(name: 'APP_DEBUG', default: false);
    }

    /**
     * @return non-empty-string
     */
    public static function appEnv(): string
    {
        return self::$appEnv ?? throw new \LogicException(message: 'Environment::prepare() was not called');
    }

    public static function appDebug(): bool
    {
        return self::$appDebug;
    }

    /**
     * @param non-empty-string $name
     * @param string $default often a built-in demo secret, hence sensitive
     */
    public static function string(string $name, #[\SensitiveParameter] string $default): string
    {
        $value = self::raw(name: $name);

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * @param non-empty-string $name
     */
    public static function int(string $name, int $default): int
    {
        $value = self::raw(name: $name);
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = filter_var(value: $value, filter: FILTER_VALIDATE_INT, options: FILTER_NULL_ON_FAILURE);

        return $parsed ?? throw new \RuntimeException(message: sprintf('%s="%s" is not an integer', $name, $value));
    }

    /**
     * @param non-empty-string $name
     */
    public static function bool(string $name, bool $default): bool
    {
        $value = self::raw(name: $name);
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = filter_var(value: $value, filter: FILTER_VALIDATE_BOOL, options: FILTER_NULL_ON_FAILURE);

        return $parsed ?? throw new \RuntimeException(message: sprintf('%s="%s" is not a boolean', $name, $value));
    }

    /**
     * @param non-empty-string $name
     */
    private static function raw(string $name): ?string
    {
        $value = getenv($name, local_only: true);
        if ($value !== false) {
            return $value;
        }
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        return isset($_ENV[$name]) && is_string($_ENV[$name]) ? $_ENV[$name] : null;
    }
}
