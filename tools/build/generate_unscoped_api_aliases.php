<?php

// phpcs:disable PSR1.Files.SideEffects -- build-tool script: intentionally mixes function definitions with executable code

/**
 * Generates a PHP file containing class_alias() calls that map scoped OpenTelemetry
 * API/Context class names back to their unscoped equivalents.
 *
 * The generated file is loaded early in PhpPartFacade::bootstrap() so that user code
 * using the standard unscoped OpenTelemetry PHP API (open-telemetry/api, open-telemetry/context)
 * transparently uses the scoped runtime without requiring a separate SDK installation.
 *
 * This solves the interoperability issue described in elastic/elastic-otel-php#386:
 *   - OpenTelemetry PHP Distro scopes its bundled dependencies under OTelDistroScoped\ to avoid conflicts
 *   - User instrumentation uses the public unscoped OpenTelemetry\API\* / OpenTelemetry\Context\* API
 *   - Without aliases, both sides have separate Globals / Context storage → spans not connected
 *   - With aliases registered before user's autoloader, both sides use the same PHP class → shared state
 *
 * Aliasing works for: classes, abstract classes, interfaces, enums.
 * Traits are skipped — class_alias() does not support traits.
 *
 * Usage:
 *   php tools/build/generate_unscoped_api_aliases.php <scoped_prefix> <vendor_dir> <output_file>
 *
 * Example:
 *   php tools/build/generate_unscoped_api_aliases.php OTelDistroScoped \
 *       _BUILT/php_code_for_packages/scoped/85/vendor \
 *       _BUILT/php_code_for_packages/scoped/85/unscoped_api_aliases.php
 */

declare(strict_types=1);

if ($_SERVER['argc'] !== 4) {
    fwrite(STDERR, "Usage: php generate_unscoped_api_aliases.php <scoped_prefix> <vendor_dir> <output_file>\n");
    exit(1);
}

assert(is_array($_SERVER['argv']));
/** @var string $prefix */
$prefix = $_SERVER['argv'][1];
/** @var string $vendorDirRaw */
$vendorDirRaw = $_SERVER['argv'][2];
$vendorDir = rtrim($vendorDirRaw, '/\\');
/** @var string $outputFile */
$outputFile = $_SERVER['argv'][3];

if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $prefix)) {
    fwrite(STDERR, "Invalid scoped prefix: {$prefix}\n");
    exit(1);
}

if (!is_dir($vendorDir)) {
    fwrite(STDERR, "Vendor directory not found: {$vendorDir}\n");
    exit(1);
}

// Packages whose public classes need unscoped aliases.
// SemConv is excluded — it only contains string constants, no type system impact.
$targetPackages = [
    'open-telemetry/api',
    'open-telemetry/context',
    'open-telemetry/sdk',
];

/**
 * Extract the first class/interface/trait/enum declaration from a PHP source file.
 *
 * @return array{namespace: string, name: string, type: string}|null
 */
function extractTypeDeclaration(string $filePath): ?array
{
    $source = file_get_contents($filePath);
    if ($source === false) {
        return null;
    }

    $tokens = token_get_all($source);
    $count = count($tokens);
    $namespace = '';
    $i = 0;

    while ($i < $count) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            $i++;
            continue;
        }

        [$id] = $token;

        if ($id === T_NAMESPACE) {
            $namespace = '';
            $i++;
            while ($i < $count) {
                $t = $tokens[$i];
                if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $t[1];
                } elseif ($t === '{' || (is_array($t) && $t[0] === T_OPEN_TAG) || (!is_array($t) && $t === ';')) {
                    break;
                }
                $i++;
            }
            $namespace = trim($namespace, '\\');
            $i++;
            continue;
        }

        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $type = match ($id) {
                T_CLASS     => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT     => 'trait',
                T_ENUM      => 'enum',
            };
            // The next T_STRING after the keyword is the type name.
            // Skip whitespace/comments. Stop if we see something unexpected
            // (e.g. anonymous class — T_CLASS immediately followed by '(').
            $j = $i + 1;
            while ($j < $count) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_STRING) {
                    return ['namespace' => $namespace, 'name' => $t[1], 'type' => $type];
                }
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY, T_ABSTRACT, T_FINAL], true)) {
                    $j++;
                    continue;
                }
                // Unexpected token (e.g. '(' for anonymous class) — not a named declaration
                break;
            }
        }

        $i++;
    }

    return null;
}

/**
 * Read the installed version of a package from vendor/composer/installed.json.
 */
function readInstalledVersion(string $vendorDir, string $packageName): string
{
    $installedJson = $vendorDir . '/composer/installed.json';
    if (!is_file($installedJson)) {
        return 'unknown';
    }
    $data = json_decode((string) file_get_contents($installedJson), true);
    if (!is_array($data)) {
        return 'unknown';
    }
    // installed.json is either a flat array or {'packages': [...], ...}
    $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
    foreach ($packages as $pkg) {
        if (is_array($pkg) && isset($pkg['name']) && $pkg['name'] === $packageName) {
            /** @var string $version */
            $version = $pkg['version'] ?? 'unknown';
            return $version;
        }
    }
    return 'unknown';
}

/**
 * Read all installed open-telemetry/* package versions from vendor/composer/installed.json.
 *
 * @return array<string, string>  package-name => version
 */
function readAllOTelInstalledVersions(string $vendorDir): array
{
    $installedJson = $vendorDir . '/composer/installed.json';
    if (!is_file($installedJson)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($installedJson), true);
    if (!is_array($data)) {
        return [];
    }
    $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
    $result = [];
    foreach ($packages as $pkg) {
        if (!is_array($pkg) || !isset($pkg['name'])) {
            continue;
        }
        /** @var string $name */
        $name = $pkg['name'];
        if (!str_starts_with($name, 'open-telemetry/')) {
            continue;
        }
        /** @var string $version */
        $version = $pkg['version'] ?? 'unknown';
        $result[$name] = $version;
    }
    ksort($result);
    return $result;
}

// --- Scan packages ---

/** @var array<string, string> $aliases Maps scoped FQCN => unscoped FQCN */
$aliases = [];
$traitCount = 0;

foreach ($targetPackages as $package) {
    $packageDir = $vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $package);
    if (!is_dir($packageDir)) {
        fwrite(STDERR, "Warning: package directory not found, skipping: {$packageDir}\n");
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $declaration = extractTypeDeclaration($file->getPathname());
        if ($declaration === null) {
            continue;
        }

        if ($declaration['type'] === 'trait') {
            $traitCount++;
            continue;
        }

        // Files in the scoped vendor are already rewritten by php-scoper:
        // namespace is OTelDistroScoped\OpenTelemetry\API\... (not the original).
        // So what we parse IS the scoped FQCN; strip the prefix to get the unscoped alias target.
        $scopedFqcn = ltrim($declaration['namespace'] . '\\' . $declaration['name'], '\\');
        $prefixedNamespace = $prefix . '\\';
        if (!str_starts_with($scopedFqcn, $prefixedNamespace)) {
            continue; // e.g. PSR interfaces that aren't scoped
        }
        $unscopedFqcn = substr($scopedFqcn, strlen($prefixedNamespace));

        // Only alias OpenTelemetry\API\*, OpenTelemetry\Context\*, and OpenTelemetry\SDK\*
        if (
            !str_starts_with($unscopedFqcn, 'OpenTelemetry\\API\\')
            && !str_starts_with($unscopedFqcn, 'OpenTelemetry\\Context\\')
            && !str_starts_with($unscopedFqcn, 'OpenTelemetry\\SDK\\')
        ) {
            continue;
        }

        $aliases[$scopedFqcn] = $unscopedFqcn;
    }
}

ksort($aliases);

$apiVersion = readInstalledVersion($vendorDir, 'open-telemetry/api');
$contextVersion = readInstalledVersion($vendorDir, 'open-telemetry/context');
$allOTelVersions = readAllOTelInstalledVersions($vendorDir);

// --- Generate output file ---

$aliasCount = count($aliases);
$lines = [];
$lines[] = '<?php';
$lines[] = '';
$lines[] = '// Auto-generated by tools/build/generate_unscoped_api_aliases.php — do not edit';
$lines[] = '// Packages:  open-telemetry/api ' . $apiVersion . ', open-telemetry/context ' . $contextVersion;
$lines[] = '// Prefix:    ' . $prefix;
$lines[] = '// Aliases:   ' . $aliasCount . ' (traits excluded: ' . $traitCount . ')';
$lines[] = '//';
$lines[] = '// Loaded by PhpPartFacade::bootstrap() when OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED is enabled.';
$lines[] = '// Registers aliases before the user\'s Composer autoloader runs, so that user code';
$lines[] = '// using the standard OpenTelemetry PHP API transparently shares the scoped runtime.';
$lines[] = '';
$lines[] = 'declare(strict_types=1);';
$lines[] = '';
$lines[] = '/** @var array<string,string> $_otelAliases Maps scoped FQCN => unscoped FQCN */';
$lines[] = '$_otelAliases = [';
foreach ($aliases as $scoped => $unscoped) {
    $lines[] = "    '{$scoped}' => '{$unscoped}',";
}
$lines[] = '];';
$lines[] = '';
$lines[] = 'foreach ($_otelAliases as $_otelScoped => $_otelUnscoped) {';
$lines[] = '    if (!class_exists($_otelUnscoped, false) && !interface_exists($_otelUnscoped, false)) {';
$lines[] = '        class_alias($_otelScoped, $_otelUnscoped);';
$lines[] = '    }';
$lines[] = '}';
$lines[] = 'unset($_otelAliases, $_otelScoped, $_otelUnscoped);';
$lines[] = '';
$lines[] = '// Bundled package versions — read by PhpPartFacade for version mismatch detection.';
$lines[] = '// Not intended for use by application code.';
$lines[] = '/** @var array<string,string> $_otelBundledVersions Maps open-telemetry/* package name => version */';
$lines[] = '$_otelBundledVersions = [';
foreach ($allOTelVersions as $pkgName => $pkgVersion) {
    $lines[] = "    '{$pkgName}' => '{$pkgVersion}',";
}
$lines[] = '];';
$lines[] = '';

if (file_put_contents($outputFile, implode("\n", $lines)) === false) {
    fwrite(STDERR, "Failed to write output file: {$outputFile}\n");
    exit(1);
}

echo "Generated {$aliasCount} aliases";
echo " (api={$apiVersion}, context={$contextVersion}, traits skipped={$traitCount})";
echo " → {$outputFile}\n";
