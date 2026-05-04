<?php

declare(strict_types=1);

if ($_SERVER['argc'] !== 3) {
    fwrite(STDERR, "Usage: php tools/build/fix_scoped_composer_autoload.php <namespace_prefix> <vendor_dir>\n");
    exit(1);
}

assert(is_array($_SERVER['argv']));
/** @var string $prefix */
$prefix = $_SERVER['argv'][1];
/** @var string $vendorDir */
$vendorDir = $_SERVER['argv'][2];
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

$replaceOrFail = static function (string $pattern, string $replacement, string $subject, string $errorMessage): string {
    $result = preg_replace($pattern, $replacement, $subject);
    if (!is_string($result)) {
        fwrite(STDERR, $errorMessage);
        exit(1);
    }

    return $result;
};

$replaceCallbackOrFail = static function (string $pattern, callable $callback, string $subject, string $errorMessage): string {
    $result = preg_replace_callback($pattern, $callback, $subject, 1);
    if (!is_string($result)) {
        fwrite(STDERR, $errorMessage);
        exit(1);
    }

    return $result;
};

$prefixedClassLoader = $prefix . '\\\\Composer\\\\Autoload\\\\ClassLoader';
$content = $replaceCallbackOrFail(
    "/if \\\('[^']*ClassLoader' === \\\$class\\\) \\\{/",
    static fn (): string => "if ('{$prefixedClassLoader}' === " . '$class' . ') {',
    $content,
    "Failed to patch loadClassLoader condition in $autoloadRealPath\n"
);

$content = $replaceOrFail(
    "/'Composer\\\\+Autoload\\\\+ClassLoader'/",
    "'{$prefixedClassLoader}'",
    $content,
    "Failed to patch ClassLoader name in $autoloadRealPath\n"
);
$content = $replaceOrFail(
    "/spl_autoload_unregister\\(array\\('ComposerAutoloaderInit/",
    "spl_autoload_unregister(array('{$prefix}\\\\ComposerAutoloaderInit",
    $content,
    "Failed to patch autoload unregister callback in $autoloadRealPath\n"
);

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
}

if (file_put_contents($autoloadRealPath, $content) === false) {
    fwrite(STDERR, "Failed to write: $autoloadRealPath\n");
    exit(1);
}

fwrite(STDOUT, "Patched scoped Composer autoloader: $autoloadRealPath\n");

// Rehash file identifiers in autoload_files.php and autoload_static.php
// to avoid collisions with the monitored application's own Composer autoloader.
// Composer tracks loaded files via $GLOBALS['__composer_autoload_files'][$hash].
// Without rehashing, both the scoped distro vendor and the app vendor would use
// identical hashes (same md5(packageName:relPath)), causing one side to skip loading.
$rehashFileIdentifiers = static function (string $filePath) use ($prefix): void {
    if (!is_file($filePath)) {
        fwrite(STDERR, "File does not exist (skipping rehash): $filePath\n");
        return;
    }

    $fileContent = file_get_contents($filePath);
    if (!is_string($fileContent)) {
        fwrite(STDERR, "Failed to read: $filePath\n");
        exit(1);
    }

    $replacedCount = 0;
    $seenOriginalHashes = [];
    $newContent = preg_replace_callback(
        "/(?<='|\"|\\\$files = array\\()([a-f0-9]{32})(?=')/",
        static function (array $match) use ($prefix, &$replacedCount, &$seenOriginalHashes): string {
            $originalHash = $match[1];
            $seenOriginalHashes[$originalHash] = true;
            $replacedCount++;
            return md5($prefix . ':' . $originalHash);
        },
        $fileContent
    );

    if (!is_string($newContent)) {
        fwrite(STDERR, "Failed to rehash file identifiers in: $filePath\n");
        exit(1);
    }

    if ($replacedCount === 0) {
        fwrite(STDOUT, "No file identifiers found to rehash in: $filePath\n");
        return;
    }

    if (file_put_contents($filePath, $newContent) === false) {
        fwrite(STDERR, "Failed to write rehashed: $filePath\n");
        exit(1);
    }

    fwrite(STDOUT, "Rehashed $replacedCount file identifier(s) in: $filePath\n");
};

$composerDir = rtrim($vendorDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR;
$rehashFileIdentifiers($composerDir . 'autoload_files.php');
$rehashFileIdentifiers($composerDir . 'autoload_static.php');
