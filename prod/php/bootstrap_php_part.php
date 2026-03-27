<?php

declare(strict_types=1);

require __DIR__ . '/ScoperConfig.php';

$vendorRootDir = __DIR__ . '/vendor_' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;

/** @var string $scopedDistroPath */
$scopedDistroPath = OpenTelemetry\Distro\OTelDistroScoperConfig::DISTRO_PATH;

/** @var array<int, string> $scopedOtelDirCandidates */
$scopedOtelDirCandidates = [
    $vendorRootDir . '/' . $scopedDistroPath,
    $vendorRootDir . '/' . $scopedDistroPath . '/OpenTelemetry',
];

/** @var array<int, string> $unscopedOtelDirCandidates */
$unscopedOtelDirCandidates = [
    $vendorRootDir . '/open-telemetry/opentelemetry-distro/src/OpenTelemetry',
    __DIR__ . '/OpenTelemetry',
];

/** @param array<int, string> $candidates */
$selectFirstExistingDistroDir = static function (array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        if (is_dir($candidate . '/Distro')) {
            return $candidate;
        }
    }

    return null;
};

$isScopedRuntime = OpenTelemetry\Distro\OTelDistroScoperConfig::ENABLED;
$otelRootDir = $isScopedRuntime // @phpstan-ignore ternary.alwaysTrue (ENABLED is a generated constant that can be true or false)
    ? $selectFirstExistingDistroDir($scopedOtelDirCandidates)
    : $selectFirstExistingDistroDir($unscopedOtelDirCandidates);

if ($otelRootDir === null) {
    $scopedExpected = implode(', ', array_map(
        static fn (string $dir): string => $dir . '/Distro',
        $scopedOtelDirCandidates
    ));
    $unscopedExpected = implode(', ', array_map(
        static fn (string $dir): string => $dir . '/Distro',
        $unscopedOtelDirCandidates
    ));

    throw new RuntimeException(
        'Cannot locate distro sources. '
        . 'scoper enabled: ' . ($isScopedRuntime ? 'true' : 'false') // @phpstan-ignore ternary.alwaysTrue
        . '; scoped candidates: ' . $scopedExpected
        . '; unscoped candidates: ' . $unscopedExpected
    );
}

$distroDir = $otelRootDir . '/Distro';

$unscopedProdPhpDirClass = 'OpenTelemetry\\Distro\\ProdPhpDir';
$unscopedPhpPartFacadeClass = 'OpenTelemetry\\Distro\\PhpPartFacade';
$unscopedInstrumentationBridgeClass = 'OpenTelemetry\\Distro\\InstrumentationBridge';

$scopedPrefix = OpenTelemetry\Distro\OTelDistroScoperConfig::PREFIX;
$scopedProdPhpDirClass = $scopedPrefix . '\\' . $unscopedProdPhpDirClass;
$scopedPhpPartFacadeClass = $scopedPrefix . '\\' . $unscopedPhpPartFacadeClass;
$scopedInstrumentationBridgeClass = $scopedPrefix . '\\' . $unscopedInstrumentationBridgeClass;

$syncScopedAlias = static function (string $unscopedClass, string $scopedClass): void {
    if (class_exists($unscopedClass, false) && !class_exists($scopedClass, false)) {
        class_alias($unscopedClass, $scopedClass, false);
        return;
    }

    if (class_exists($scopedClass, false) && !class_exists($unscopedClass, false)) {
        class_alias($scopedClass, $unscopedClass, false);
    }
};

require $distroDir . '/ProdPhpDir.php';
require $distroDir . '/Util/HiddenConstructorTrait.php';
require $distroDir . '/Util/SingletonInstanceTrait.php';
require $distroDir . '/InstrumentationBridge.php';
require $distroDir . '/PhpPartFacade.php';

$syncScopedAlias($unscopedProdPhpDirClass, $scopedProdPhpDirClass);
$syncScopedAlias($unscopedPhpPartFacadeClass, $scopedPhpPartFacadeClass);
$syncScopedAlias($unscopedInstrumentationBridgeClass, $scopedInstrumentationBridgeClass);

$prodPhpDirClass = $isScopedRuntime ? $scopedProdPhpDirClass : $unscopedProdPhpDirClass; // @phpstan-ignore ternary.alwaysTrue
$prodPhpDirClass::$fullPath = __DIR__;
if (property_exists($prodPhpDirClass, 'shadowOtelRootPath')) {
    $prodPhpDirClass::$shadowOtelRootPath = $isScopedRuntime ? $otelRootDir : null; // @phpstan-ignore ternary.alwaysTrue
}
