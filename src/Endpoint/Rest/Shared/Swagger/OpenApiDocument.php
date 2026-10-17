<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\Swagger;

use Yiisoft\Swagger\Service\SwaggerService;

/**
 * The HTTP contract, generated from the actions' OpenAPI attributes
 * (yiisoft/yii-swagger over swagger-php): the one statement the checkout
 * validates against, the contract page generates from and Swagger UI renders.
 *
 * The built-in server boots the container on every request and a scan costs
 * far more than the request, so the generated document is kept as a PHP file
 * and regenerated only when a file under the scanned directory is newer.
 */
final readonly class OpenApiDocument
{
    public const string ANNOTATION_PATH = '@src/Endpoint/Rest';

    /**
     * @param non-empty-string $sourceDirectory the directory ANNOTATION_PATH names
     * @param non-empty-string $cacheFile
     */
    public function __construct(
        private SwaggerService $swagger,
        private string $sourceDirectory,
        private string $cacheFile,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (!is_file(filename: $this->cacheFile) || filemtime(filename: $this->cacheFile) < $this->newestSource()) {
            $this->write(document: $this->generate());
        }

        /** @var array<string, mixed> */
        return require $this->cacheFile;
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        // swagger-php 4 still calls SplObjectStorage methods PHP 8.5
        // deprecates; the web runtime turns any deprecation into an exception.
        // Only those, from that package, are dropped; everything else reaches
        // the handler that was there.
        $previous = set_error_handler(callback: static fn (): bool => false);
        restore_error_handler();
        set_error_handler(
            callback: static function (int $level, string $message, string $file = '', int $line = 0) use ($previous): bool {
                if (str_contains(haystack: $file, needle: '/zircote/swagger-php/')) {
                    return true;
                }

                return $previous !== null && $previous($level, $message, $file, $line);
            },
            error_levels: E_DEPRECATED,
        );

        try {
            $json = $this->swagger->fetch(annotationPaths: [self::ANNOTATION_PATH])->toJson();
        } finally {
            restore_error_handler();
        }

        /** @var array<string, mixed> */
        return json_decode(json: $json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Written aside and renamed: a request racing this one never includes a
     * half-written file.
     *
     * @param array<string, mixed> $document
     */
    private function write(array $document): void
    {
        $directory = dirname(path: $this->cacheFile);
        if (!is_dir(filename: $directory)) {
            mkdir(directory: $directory, permissions: 0o775, recursive: true);
        }
        $temporary = $this->cacheFile . '.' . bin2hex(string: random_bytes(length: 4)) . '.tmp';
        file_put_contents(filename: $temporary, data: '<?php return ' . var_export(value: $document, return: true) . ";\n");
        rename(from: $temporary, to: $this->cacheFile);
    }

    private function newestSource(): int
    {
        $newest = 0;
        /** @var iterable<\SplFileInfo> $files SKIP_DOTS without CURRENT_AS_PATHNAME yields file info objects */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $newest = max($newest, (int) $file->getMTime());
        }

        return $newest;
    }
}
