<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/build/fix_scoped_composer_autoload.php <namespace_prefix> <vendor_dir>\n");
    exit(1);
}

$prefix = $argv[1];
$vendorDir = $argv[2];
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $prefix)) {
    fwrite(STDERR, "Invalid namespace prefix: $prefix\n");
    exit(1);
}

$autoloadRealPath = rtrim($vendorDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_real.php';
if (!is_file($autoloadRealPath)) {
    fwrite(STDERR, "File does not exist: $autoloadRealPath\n");
    exit(1);
}

$content = file_get_contents($autoloadRealPath);
if (!is_string($content)) {
    fwrite(STDERR, "Failed to read: $autoloadRealPath\n");
    exit(1);
}

$prefixedClassLoader = $prefix . '\\\\Composer\\\\Autoload\\\\ClassLoader';
$content = preg_replace_callback(
    "/if \\\('[^']*ClassLoader' === \\\$class\\\) \\\{/",
    static fn() => "if ('" . $prefixedClassLoader . "' === \\$class) {",
    $content,
    1
);

if (!is_string($content)) {
    fwrite(STDERR, "Failed to patch loadClassLoader condition in $autoloadRealPath\\n");
    exit(1);
}

$content = preg_replace(
    "/'Composer\\\\+Autoload\\\\+ClassLoader'/",
    "'{$prefixedClassLoader}'",
    $content
);
$content = preg_replace(
    "/spl_autoload_unregister\\(array\\('ComposerAutoloaderInit/",
    "spl_autoload_unregister(array('{$prefix}\\\\ComposerAutoloaderInit",
    $content
);

if (!is_string($content)) {
    fwrite(STDERR, "Failed to patch $autoloadRealPath\n");
    exit(1);
}

if (!str_contains($content, 'OTEL scoped autoload fix begin')) {
    if (!preg_match('/ComposerStaticInit([a-f0-9]+)/', $content, $matches)) {
        fwrite(STDERR, "Failed to determine ComposerStaticInit hash in $autoloadRealPath\\n");
        exit(1);
    }

    $composerStaticClassName = 'ComposerStaticInit' . $matches[1];
    $autoloadStaticRequire = "require __DIR__ . '/autoload_static.php';";
    $autoloadStaticPatchTemplate = <<<'PHP_BLOCK'
require __DIR__ . '/autoload_static.php';
        // OTEL scoped autoload fix begin
        $scopedPrefix = '__PREFIX__\\';
        $composerStaticClass = __NAMESPACE__ . '\\Composer\\Autoload\\__STATIC_CLASS__';
        if (class_exists($composerStaticClass, false)) {
            $newPrefixLengthsPsr4 = [];
            $newPrefixDirsPsr4 = [];
            foreach ($composerStaticClass::$prefixDirsPsr4 as $namespace => $dirs) {
                $scopedNamespace = str_starts_with($namespace, $scopedPrefix) ? $namespace : $scopedPrefix . $namespace;
                $newPrefixDirsPsr4[$scopedNamespace] = $dirs;
                $newPrefixLengthsPsr4[$scopedNamespace[0]][$scopedNamespace] = strlen($scopedNamespace);
            }
            $composerStaticClass::$prefixDirsPsr4 = $newPrefixDirsPsr4;
            $composerStaticClass::$prefixLengthsPsr4 = $newPrefixLengthsPsr4;
            $newClassMap = [];
            foreach ($composerStaticClass::$classMap as $className => $classPath) {
                $newClassMap[$className] = $classPath;
                if (str_contains($className, '\\') && !str_starts_with($className, $scopedPrefix)) {
                    $newClassMap[$scopedPrefix . $className] = $classPath;
                }
            }
            $composerStaticClass::$classMap = $newClassMap;
        }
        // OTEL scoped autoload fix end
PHP_BLOCK;
    $autoloadStaticPatch = str_replace(
        ['__PREFIX__', '__STATIC_CLASS__'],
        [$prefix, $composerStaticClassName],
        $autoloadStaticPatchTemplate
    );

    $content = str_replace($autoloadStaticRequire, $autoloadStaticPatch, $content);
    if (!is_string($content)) {
        fwrite(STDERR, "Failed to inject autoload static patch into $autoloadRealPath\\n");
        exit(1);
    }
}

if (file_put_contents($autoloadRealPath, $content) === false) {
    fwrite(STDERR, "Failed to write: $autoloadRealPath\n");
    exit(1);
}

fwrite(STDOUT, "Patched scoped Composer autoloader: $autoloadRealPath\n");
