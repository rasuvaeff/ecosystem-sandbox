<?php

declare(strict_types=1);

// Every `App\…` class named in config/, bin/, tests/ and src/ must exist. php -l
// cannot see a stale reference and psalm reads src/ only, so a moved class
// otherwise surfaces as a runtime error inside one scenario.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$missing = [];
$files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)), '#/(config|bin|tests|src)/.*\.php$#');
foreach ($files as $file) {
    $path = (string) $file;
    if (str_contains($path, '/vendor/') || str_ends_with($path, '.merge-plan.php')) {
        continue;
    }
    $code = (string) file_get_contents($path);
    preg_match_all('/\\\\?\bApp\\\\(?:[A-Z][A-Za-z0-9]*\\\\)*[A-Z][A-Za-z0-9]*\b/', $code, $matches);
    foreach (array_unique($matches[0]) as $name) {
        $name = ltrim($name, '\\');
        $directory = str_starts_with($name, 'App\\Tests\\')
            ? $root . '/tests/' . str_replace('\\', '/', substr($name, 10))
            : $root . '/src/' . str_replace('\\', '/', substr($name, 4));
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name) && !trait_exists($name) && !is_dir($directory)) {
            $missing[] = substr($path, strlen($root) + 1) . ': ' . $name;
        }
    }
}
// PSR-4: a file's namespace and class must match its path, or the class is
// unloadable while every reference to it by short name still reads fine.
foreach (['src' => 'App\\', 'tests' => 'App\\Tests\\'] as $directory => $prefix) {
    $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)), '#\.php$#');
    foreach ($files as $file) {
        $relative = substr((string) $file, strlen($root . '/' . $directory . '/'), -4);
        if (!str_contains($relative, '/') && in_array($relative, ['autoload', 'Environment'], true) === false && $directory === 'src' && preg_match('/^[a-z]/', $relative) === 1) {
            continue;
        }
        $expected = $prefix . str_replace('/', '\\', $relative);
        $code = (string) file_get_contents((string) $file);
        if (preg_match('/^namespace ([^;]+);/m', $code, $namespace) !== 1 || !preg_match('/^(?:final |abstract |readonly )*(?:class|interface|enum|trait) (\w+)/m', $code, $class)) {
            continue;
        }
        if ($expected !== $namespace[1] . '\\' . $class[1]) {
            $missing[] = $directory . '/' . $relative . '.php: declares ' . $namespace[1] . '\\' . $class[1];
        }
    }
}
if ($missing !== []) {
    fwrite(STDERR, "Unknown App classes:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}
echo "CLASS REFERENCES PASS\n";
