<?php

declare(strict_types=1);

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

/*
Dummy comment to verify PHP source code max allowed line length (which is 200).
PHP source code max allowed line length is configured in <repo root>/phpcs.xml

1--------10--------20--------30--------40--------50--------60--------70--------80--------90--------100-------110-------120-------130-------140-------150-------160-------170-------180-------190------->
|--------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|---------|
*/
