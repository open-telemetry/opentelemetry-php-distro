<?php

/**
 * Applies four post-scoping fixes to a php-scoper-generated vendor directory:
 *
 * 1. autoload_real.php — rewrites ClassLoader references to use the scoped namespace prefix
 *    so that the scoped and application Composer autoloaders do not collide.
 *
 * 2. autoload_real.php — rewrites PSR-4 prefixes and classmap after autoload_static.php is
 *    loaded, prepending the scoped namespace prefix to all entries.
 *
 * 3. autoload_files.php / autoload_static.php — prepends earlySetup.php as the first entry
 *    in the Composer files loop so that PhpPartFacade::earlySetup() runs before sdk/_autoload.php
 *    and any instrumentation _register.php files. This prevents race conditions when
 *    instrumentations (e.g. tbachert/otel-instrumentation-runtime-metrics) call
 *    Globals::meterProvider() eagerly inside register() and trigger premature SDK initialization.
 *
 * 4. autoload_files.php / autoload_static.php — rehashes file identifiers (md5 keys) with the
 *    scoped prefix so that they do not collide with the application's own Composer autoloader
 *    ($GLOBALS['__composer_autoload_files'] collision prevention).
 *
 * 5. autoload_files.php / autoload_static.php — reorders exporter-otlp/_register.php before
 *    sdk/_autoload.php so that the OTLP exporter factory is registered before the SDK initializer
 *    runs, preventing "Span exporter factory not defined for: otlp" errors.
 *
 * Usage: php tools/build/fix_scoped_composer_autoload.php <namespace_prefix> <vendor_dir>
 */

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

$composerDir = rtrim($vendorDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR;
$autoloadRealPath = $composerDir . 'autoload_real.php';
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

// --- Fix 1: Rewrite ClassLoader references to use the scoped namespace ---

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

// --- Fix 2: Rewrite PSR-4 prefixes and classmap with the scoped namespace prefix ---

// Load excluded namespaces from php-scoper.inc.php - single source of truth.
// We use a closure to isolate the require's variable scope so that $prefix, $extensionFunctionFqcns
// and the patcher closures defined in php-scoper.inc.php don't leak into this script's scope.
/** @var list<string> $excludedNamespaces */
$excludedNamespaces = (static function (): array {
    /** @var array{exclude-namespaces: list<string>} $config */
    $config = require __DIR__ . '/php-scoper.inc.php';
    return $config['exclude-namespaces'];
})();

// Build a PHP single-quoted array literal suitable for embedding in autoload_real.php.
// Each namespace needs a trailing backslash to match the PSR-4 prefix convention.
// e.g. 'Psr\Http\Client' (actual string) -> "'Psr\\Http\\Client\\'" (PHP source literal)
$excludedPsr4PrefixesCode = '[' . implode(', ', array_map(
    static fn(string $ns): string => "'" . str_replace('\\', '\\\\', $ns . '\\') . "'",
    $excludedNamespaces
)) . ']';

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
        // The scoped vendor's autoload_static.php retains unscoped PSR-4 prefixes
        // (php-scoper does not rewrite them). Excluded namespaces (php-scoper
        // exclude-namespaces: see php-scoper.inc.php) keep their unscoped prefix because
        // the scoped vendor files for those packages still declare unscoped classes and code
        // references them by unscoped names. All other prefixes are converted to the scoped
        // variant so that class_exists('OpenTelemetry\...') does NOT accidentally trigger
        // loading of a scoped-vendor file (which declares OTelDistroScoped\...)
        // and cause a "Cannot redeclare class" fatal error.
        $scopedPrefix = '__PREFIX__\\';
        $excludedPsr4Prefixes = __EXCLUDED_PSR4_PREFIXES__;
        $composerStaticClass = __NAMESPACE__ . '\\Composer\\Autoload\\__STATIC_CLASS__';
        if (class_exists($composerStaticClass, false)) {
            $newPrefixLengthsPsr4 = [];
            $newPrefixDirsPsr4 = [];
            foreach ($composerStaticClass::$prefixDirsPsr4 as $namespace => $dirs) {
                $isExcluded = false;
                foreach ($excludedPsr4Prefixes as $excl) {
                    if (str_starts_with($namespace, $excl)) {
                        $isExcluded = true;
                        break;
                    }
                }
                if ($isExcluded) {
                    // Excluded from scoping: files declare unscoped classes. Keep unscoped prefix only.
                    $newPrefixDirsPsr4[$namespace] = $dirs;
                    $newPrefixLengthsPsr4[$namespace[0]][$namespace] = strlen($namespace);
                } else {
                    // Scoped: files declare scoped classes. Use scoped prefix only.
                    $scopedNamespace = str_starts_with($namespace, $scopedPrefix) ? $namespace : $scopedPrefix . $namespace;
                    $newPrefixDirsPsr4[$scopedNamespace] = $dirs;
                    $newPrefixLengthsPsr4[$scopedNamespace[0]][$scopedNamespace] = strlen($scopedNamespace);
                }
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
        ['__PREFIX__', '__STATIC_CLASS__', '__EXCLUDED_PSR4_PREFIXES__'],
        [$prefix, $composerStaticClassName, $excludedPsr4PrefixesCode],
        $autoloadStaticPatchTemplate
    );

    $content = str_replace($autoloadStaticRequire, $autoloadStaticPatch, $content);
}

if (file_put_contents($autoloadRealPath, $content) === false) {
    fwrite(STDERR, "Failed to write: $autoloadRealPath\n");
    exit(1);
}

fwrite(STDOUT, "Patched scoped Composer autoloader: $autoloadRealPath\n");

// --- Fix 3: Prepend earlySetup.php as the first entry in the files autoload loop ---

$prependEarlySetupEntry = static function (
    string $filePath,
    string $entry,
    string $insertAfter
): void {
    if (!is_file($filePath)) {
        fwrite(STDERR, "File does not exist (skipping early setup prepend): $filePath\n");
        return;
    }

    $content = file_get_contents($filePath);
    if (!is_string($content)) {
        fwrite(STDERR, "Failed to read: $filePath\n");
        exit(1);
    }

    if (str_contains($content, 'earlySetup.php')) {
        fwrite(STDOUT, "Early setup entry already present in: $filePath\n");
        return;
    }

    $newContent = str_replace($insertAfter, $insertAfter . $entry, $content);
    if ($newContent === $content) {
        fwrite(STDERR, "Failed to find insertion point '$insertAfter' in: $filePath\n");
        exit(1);
    }

    if (file_put_contents($filePath, $newContent) === false) {
        fwrite(STDERR, "Failed to write: $filePath\n");
        exit(1);
    }

    fwrite(STDOUT, "Prepended early setup entry in: $filePath\n");
};

$earlySetupHash = md5('otel-distro:OpenTelemetry/Distro/earlySetup.php');
$prependEarlySetupEntry(
    $composerDir . 'autoload_files.php',
    "'" . $earlySetupHash . "' => \$baseDir . '/OpenTelemetry/Distro/earlySetup.php', ",
    'return array('
);
$prependEarlySetupEntry(
    $composerDir . 'autoload_static.php',
    "'" . $earlySetupHash . "' => __DIR__ . '/../../OpenTelemetry/Distro/earlySetup.php', ",
    'public static $files = array('
);

// --- Fix 4: Rehash file identifiers to avoid collision with the application's autoloader ---
// Composer tracks loaded files via $GLOBALS['__composer_autoload_files'][$hash].
// Without rehashing, both the scoped distro vendor and the app vendor would use identical
// hashes (same md5(packageName:relPath)), causing one side to silently skip loading.

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
    $newContent = preg_replace_callback(
        "/(?<='|\"|\\\$files = array\\()([a-f0-9]{32})(?=')/",
        static function (array $match) use ($prefix, &$replacedCount): string {
            $replacedCount++;
            return md5($prefix . ':' . $match[1]);
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

$rehashFileIdentifiers($composerDir . 'autoload_files.php');
$rehashFileIdentifiers($composerDir . 'autoload_static.php');

// --- Fix 5: Reorder exporter-otlp/_register.php before sdk/_autoload.php ---
// sdk/_autoload.php registers a Globals initializer that, when triggered, initializes the SDK
// and creates OTLP exporters. exporter-otlp/_register.php registers the OTLP exporter factory
// used during that initialization. If sdk/_autoload.php runs first and an instrumentation
// triggers early SDK initialization, the factory is not yet registered and initialization fails.

$reorderAutoloadEntries = static function (string $filePath, string $pathToMove, string $insertBefore): void {
    if (!is_file($filePath)) {
        fwrite(STDERR, "File does not exist (skipping reorder): $filePath\n");
        return;
    }

    $content = file_get_contents($filePath);
    if (!is_string($content)) {
        fwrite(STDERR, "Failed to read: $filePath\n");
        exit(1);
    }

    $pathToMoveMarker = $pathToMove . "'";
    $insertBeforeMarker = $insertBefore . "'";

    $pathToMovePos = strpos($content, $pathToMoveMarker);
    $insertBeforePos = strpos($content, $insertBeforeMarker);

    if ($pathToMovePos === false || $insertBeforePos === false) {
        fwrite(STDOUT, "Skipping reorder (markers not found) in: $filePath\n");
        return;
    }

    if ($pathToMovePos < $insertBeforePos) {
        fwrite(STDOUT, "No reorder needed in: $filePath\n");
        return;
    }

    // Find the start of the entry by searching backwards for the ', ' separator
    $sepPos = strrpos(substr($content, 0, $pathToMovePos), ", '");
    if ($sepPos === false) {
        fwrite(STDERR, "Cannot find entry boundary for '$pathToMove' in: $filePath\n");
        exit(1);
    }

    $entryStart = $sepPos + 2; // skip ', ' to reach the opening quote of the hash
    $entryToMove = substr($content, $entryStart, $pathToMovePos + strlen($pathToMoveMarker) - $entryStart);

    $newContent = str_replace(', ' . $entryToMove, '', $content);
    if ($newContent === $content) {
        fwrite(STDERR, "Failed to remove '$pathToMove' entry from: $filePath\n");
        exit(1);
    }

    $insertBeforePos2 = strpos($newContent, $insertBeforeMarker);
    if ($insertBeforePos2 === false) {
        fwrite(STDERR, "Cannot find '$insertBefore' entry after removal in: $filePath\n");
        exit(1);
    }

    $insertSepPos = strrpos(substr($newContent, 0, $insertBeforePos2), ", '");
    if ($insertSepPos === false) {
        fwrite(STDERR, "Cannot find boundary for '$insertBefore' entry in: $filePath\n");
        exit(1);
    }

    $insertEntryStart = $insertSepPos + 2;
    $insertBeforeEntry = substr($newContent, $insertEntryStart, $insertBeforePos2 + strlen($insertBeforeMarker) - $insertEntryStart);

    $newContent = str_replace($insertBeforeEntry, $entryToMove . ', ' . $insertBeforeEntry, $newContent);

    if ($newContent === $content) {
        fwrite(STDERR, "Reorder had no effect in: $filePath\n");
        exit(1);
    }

    if (file_put_contents($filePath, $newContent) === false) {
        fwrite(STDERR, "Failed to write reordered file: $filePath\n");
        exit(1);
    }

    fwrite(STDOUT, "Reordered: '$pathToMove' now runs before '$insertBefore' in: $filePath\n");
};

$reorderAutoloadEntries(
    $composerDir . 'autoload_files.php',
    '/open-telemetry/exporter-otlp/_register.php',
    '/open-telemetry/sdk/_autoload.php'
);
$reorderAutoloadEntries(
    $composerDir . 'autoload_static.php',
    '/open-telemetry/exporter-otlp/_register.php',
    '/open-telemetry/sdk/_autoload.php'
);
