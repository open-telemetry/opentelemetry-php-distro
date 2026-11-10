<?php

/**
 * Fixes the Composer files-autoload order in a vendor directory so that instrumentations which
 * eagerly resolve the OTel SDK during `require vendor/autoload.php` (e.g.
 * open-telemetry/opentelemetry-metrics-runtime, which calls Globals::meterProvider() unconditionally
 * inside its register()) cannot run before the distro or the OTLP exporter are ready:
 *
 * 1. Prepends earlySetup.php as the first files-autoload entry, so
 *    PhpPartFacade::earlySetup() - which registers the distro's resource-attribute override and its
 *    native OTLP serializer/transport shadowing - runs before sdk/_autoload.php or any
 *    instrumentation's _register.php file.
 *
 * 2. Reorders open-telemetry/exporter-otlp/_register.php before open-telemetry/sdk/_autoload.php.
 *    Composer's natural ordering puts a package's own dependencies first, so sdk/_autoload.php (a
 *    dependency of exporter-otlp) normally runs before exporter-otlp/_register.php. If an
 *    instrumentation then triggers eager SDK initialization, the OTLP span/metric/log exporter
 *    factories are not yet registered and initialization fails with "Span exporter factory not
 *    defined for: otlp".
 *
 * The script fails (exit code 1) instead of silently doing nothing when what it relies on is not
 * there: a missing composer/autoload_files.php, or an autoload file that does not list
 * open-telemetry/exporter-otlp/_register.php or open-telemetry/sdk/_autoload.php. Every vendor of the
 * distro contains both packages, so a missing marker means that upstream renamed or moved one of these
 * files. Without this failure the build would stay green while the OTLP exporter factories are again
 * registered too late.
 *
 * Needed for BOTH scoped and not_scoped vendors - the relative path from a vendor's composer/
 * directory up to OpenTelemetry/Distro/earlySetup.php is identical in both layouts (vendor is
 * always a sibling of OpenTelemetry/), and the exporter-otlp/sdk ordering problem is not
 * scoping-specific either. This script is called against each vendor directory separately and does
 * not depend on scoping.
 *
 * Usage: php tools/build/fix_composer_autoload_order.php <vendor_dir>
 */

declare(strict_types=1);

if ($_SERVER['argc'] !== 2) {
    fwrite(STDERR, "Usage: php tools/build/fix_composer_autoload_order.php <vendor_dir>\n");
    exit(1);
}

assert(is_array($_SERVER['argv']));
/** @var string $vendorDir */
$vendorDir = $_SERVER['argv'][1];
$composerDir = rtrim($vendorDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR;

// --- Fix 1: Prepend earlySetup.php as the first entry in the files autoload loop ---

// $insertAfterPattern is a regex (without delimiters/flags) matching the array-opening line. php-scoper
// and plain Composer format this slightly differently ("array(" vs "array (" with a space), so this
// matches on the pattern and inserts right after whatever was actually matched, rather than assuming
// one exact literal string.
$prependEarlySetupEntry = static function (
    string $filePath,
    string $entry,
    string $insertAfterPattern,
    bool $required
): void {
    if (!is_file($filePath)) {
        if ($required) {
            fwrite(STDERR, "Required file does not exist (cannot prepend early setup): $filePath\n");
            exit(1);
        }
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

    if (!preg_match('/' . $insertAfterPattern . '/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "Failed to find insertion point matching '$insertAfterPattern' in: $filePath\n");
        exit(1);
    }
    $insertPos = $matches[0][1] + strlen($matches[0][0]);
    $newContent = substr($content, 0, $insertPos) . $entry . substr($content, $insertPos);

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
    "return array\\s*\\(",
    true
);
$prependEarlySetupEntry(
    $composerDir . 'autoload_static.php',
    "'" . $earlySetupHash . "' => __DIR__ . '/../../OpenTelemetry/Distro/earlySetup.php', ",
    "public static \\\$files = array\\s*\\(",
    false
);

// --- Fix 2: Reorder exporter-otlp/_register.php before sdk/_autoload.php ---

// Locates the array entry ('<32-hex-hash>' => <expr>,) whose value contains $marker and returns
// [entryStartOffset, entryTextIncludingTrailingComma], or null if not found. Composer emits these
// files with one entry per line, but php-scoper's own generator emits the whole array as a single
// line - so this works off entry boundaries (the preceding hash key and the following comma; the
// expressions themselves never contain a literal comma) rather than assuming a fixed line/separator
// structure.
$findEntryContaining = static function (string $content, string $marker): ?array {
    $markerPos = strpos($content, $marker);
    if ($markerPos === false) {
        return null;
    }

    if (!preg_match_all("/'[0-9a-f]{32}'\\s*=>/", $content, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $entryStart = null;
    foreach ($matches[0] as $match) {
        $offset = (int) $match[1];
        if ($offset > $markerPos) {
            break;
        }
        $entryStart = $offset;
    }
    if ($entryStart === null) {
        return null;
    }

    $commaPos = strpos($content, ',', $markerPos + strlen($marker));
    if ($commaPos === false) {
        return null;
    }
    $entryEnd = $commaPos + 1; // include the trailing comma

    return [$entryStart, substr($content, $entryStart, $entryEnd - $entryStart)];
};

$registeredThroughSpi = static function (string $autoloadFilePath): bool {
    $spiData = dirname($autoloadFilePath) . DIRECTORY_SEPARATOR . 'GeneratedServiceProviderData.php';
    $content = is_file($spiData) ? file_get_contents($spiData) : false;
    return is_string($content) && str_contains($content, '\\OpenTelemetry\\Contrib\\Otlp\\Registrar::class');
};

$reorderAutoloadEntries = static function (string $filePath, string $pathToMove, string $insertBefore, bool $required) use ($findEntryContaining, $registeredThroughSpi): void {
    if (!is_file($filePath)) {
        if ($required) {
            fwrite(STDERR, "Required file does not exist (cannot reorder): $filePath\n");
            exit(1);
        }
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

    // Newer exporter-otlp registers its factories through SPI (a Registrar listed in Composer's
    // GeneratedServiceProviderData.php, loaded lazily on first lookup) and no longer has a files-autoload
    // entry, so there is nothing to reorder. The SPI registration is checked, so that a renamed file still fails.
    if ($pathToMovePos === false && $insertBeforePos !== false && $registeredThroughSpi($filePath)) {
        fwrite(STDOUT, "No reorder needed in $filePath: '$pathToMove' is not a files-autoload entry, the exporter is registered through SPI\n");
        return;
    }

    if ($pathToMovePos === false || $insertBeforePos === false) {
        $missingMarkers = [];
        if ($pathToMovePos === false) {
            $missingMarkers[] = $pathToMove;
        }
        if ($insertBeforePos === false) {
            $missingMarkers[] = $insertBefore;
        }
        fwrite(
            STDERR,
            "Cannot reorder, not listed in $filePath: " . implode(', ', $missingMarkers) . "\n"
            . "Every vendor of the distro contains both packages, so one of these files was probably renamed or moved upstream."
            . " Update this script, otherwise the OTLP exporter factories are registered after the SDK autoloads (see the comment at the top).\n"
        );
        exit(1);
    }

    if ($pathToMovePos < $insertBeforePos) {
        fwrite(STDOUT, "No reorder needed in: $filePath\n");
        return;
    }

    $movedEntry = $findEntryContaining($content, $pathToMoveMarker);
    if ($movedEntry === null) {
        fwrite(STDERR, "Cannot find entry bounds for '$pathToMove' in: $filePath\n");
        exit(1);
    }
    [$movedEntryStart, $movedEntryText] = $movedEntry;

    $newContent = substr($content, 0, $movedEntryStart) . substr($content, $movedEntryStart + strlen($movedEntryText));

    $insertBeforeEntry = $findEntryContaining($newContent, $insertBeforeMarker);
    if ($insertBeforeEntry === null) {
        fwrite(STDERR, "Cannot find entry bounds for '$insertBefore' after removal in: $filePath\n");
        exit(1);
    }
    [$insertBeforeEntryStart] = $insertBeforeEntry;

    $newContent = substr($newContent, 0, $insertBeforeEntryStart) . $movedEntryText . substr($newContent, $insertBeforeEntryStart);

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
    '/open-telemetry/sdk/_autoload.php',
    true
);
$reorderAutoloadEntries(
    $composerDir . 'autoload_static.php',
    '/open-telemetry/exporter-otlp/_register.php',
    '/open-telemetry/sdk/_autoload.php',
    false
);
