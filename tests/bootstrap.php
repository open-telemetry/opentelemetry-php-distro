<?php // phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation;

use OTelDistroTests\Util\RepoRootDir;
use OTelDistroTests\Util\ExceptionUtil;

// Ensure that composer has installed all dependencies
if (!file_exists($vendorAutoload = (__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'))) {
    die("Error: $vendorAutoload is missing - dependencies must be installed using composer" . PHP_EOL);
}

// Disable deprecation notices starting from PHP 8.4
// Deprecated: funcAbc(): Implicitly marking parameter $xyz as nullable is deprecated, the explicit nullable type must be used instead
error_reporting(PHP_VERSION_ID < 80400 ? E_ALL : (E_ALL & ~E_DEPRECATED));

require $vendorAutoload;
// Substitutes should be loaded IMMEDIATELY AFTER vendor
require __DIR__ . '/substitutes/load.php';

ExceptionUtil::runCatchLogRethrow(
    function (): void {
        RepoRootDir::setFullPath(__DIR__ . '/..');

        require __DIR__ . '/polyfills/load.php';
        require __DIR__ . '/otel_distro_extension_stubs/load.php';
        require __DIR__ . '/dummyFuncForTestsWithoutNamespace.php';
        require __DIR__ . '/OTelDistroTests/dummyFuncForTestsWithNamespace.php';
    }
);

// For BootstrapTests we need to have the same class aliases regardless of whether scoping is enabled or not, so we sync them in the bootstrap itself
// (instead of relying on the PhpPartFacade which is not loaded in tests context).

$syncScopedAlias = static function (string $unscopedClass, string $scopedClass): void {
    if (class_exists($unscopedClass, false) && !class_exists($scopedClass, false)) {
        class_alias($unscopedClass, $scopedClass, false);
        return;
    }

    if (class_exists($scopedClass, false) && !class_exists($unscopedClass, false)) {
        class_alias($scopedClass, $unscopedClass, false);
    }
};


$unscopedProdPhpDirClass = 'OpenTelemetry\\Distro\\ProdPhpDir';
$unscopedPhpPartFacadeClass = 'OpenTelemetry\\Distro\\PhpPartFacade';
$unscopedInstrumentationBridgeClass = 'OpenTelemetry\\Distro\\InstrumentationBridge';

$scopedPrefix = 'OTelDistroScoped';
$scopedProdPhpDirClass = $scopedPrefix . '\\' . $unscopedProdPhpDirClass;
$scopedPhpPartFacadeClass = $scopedPrefix . '\\' . $unscopedPhpPartFacadeClass;
$scopedInstrumentationBridgeClass = $scopedPrefix . '\\' . $unscopedInstrumentationBridgeClass;


$syncScopedAlias($unscopedProdPhpDirClass, $scopedProdPhpDirClass);
$syncScopedAlias($unscopedPhpPartFacadeClass, $scopedPhpPartFacadeClass);
$syncScopedAlias($unscopedInstrumentationBridgeClass, $scopedInstrumentationBridgeClass);


function hook(
    ?string $class,
    string $function,
    ?\Closure $pre = null,
    ?\Closure $post = null,
): bool {
    $scopedClass = 'OTelDistroScoped\\OpenTelemetry\\Distro\\InstrumentationBridge';
    if (class_exists($scopedClass, false)) {
        /** @var \OpenTelemetry\Distro\InstrumentationBridge $bridge */
        $bridge = $scopedClass::singletonInstance();
        return $bridge->hook($class, $function, $pre, $post);
    }

    return \OpenTelemetry\Distro\InstrumentationBridge::singletonInstance()->hook($class, $function, $pre, $post);
}


/*
Dummy comment to verify PHP source code max allowed line length (which is 200).
PHP source code max allowed line length is configured in <repo root>/phpcs.xml

1--------10--------20--------30--------40--------50--------60--------70--------80--------90--------100-------110-------120-------130-------140-------150-------160-------170-------180-------190------->
|--------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|
*/
