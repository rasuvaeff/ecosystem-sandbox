<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Every class, interface and enum under src/, by its PSR-4 name, with its
 * file's text: what the architecture rules are checked against.
 */
final class SourceClasses
{
    /**
     * @return array<class-string, string> class name => source
     */
    public static function all(): array
    {
        $root = dirname(__DIR__, levels: 2) . '/src';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr(string: $file->getPathname(), offset: strlen(string: $root) + 1, length: -4);
            $class = 'App\\' . str_replace(search: '/', replace: '\\', subject: $relative);
            if (class_exists(class: $class) || interface_exists(interface: $class) || enum_exists(enum: $class)) {
                /** @var class-string $class */
                $classes[$class] = (string) file_get_contents(filename: $file->getPathname());
            }
        }
        ksort($classes);

        return $classes;
    }
}
