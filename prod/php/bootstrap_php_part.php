<?php

/** @noinspection PhpFullyQualifiedNameUsageInspection */

declare(strict_types=1);

use OpenTelemetry\Distro\OTelDistroScoperConfig;

/*
 * Directory Layout after package install
 *
 *          bootstrap_php_part.php
 *          ScoperConfig.php
 *          85 (<PHP major><PHP minor>)
 *              not_scoped
 *                  OpenTelemetry/  (under this directory the layout is the same as in <repo>/prod/php/OpenTelemetry/)
 *                      ...
 *                      Distro/
 *                      ...
 *                  vendor/
 *              scoped
 *                  OpenTelemetry/  (under this directory the layout is the same as in <repo>/prod/php/OpenTelemetry/)
 *                      ...
 *                      Distro/
 *                      ...
 *                  vendor/
 */

$isScopingEnabled = \OpenTelemetry\Distro\get_config_option_by_name('scoped_deps_enabled');
$prodPhpDir = __DIR__ . DIRECTORY_SEPARATOR . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . DIRECTORY_SEPARATOR . ($isScopingEnabled ? 'scoped' : 'not_scoped');
$vendorDir = $prodPhpDir . DIRECTORY_SEPARATOR . 'vendor';
$otelDistroDir = $prodPhpDir . DIRECTORY_SEPARATOR . 'OpenTelemetry' . DIRECTORY_SEPARATOR . 'Distro';

require __DIR__ . DIRECTORY_SEPARATOR . 'ScoperConfig.php';
$scopePrefixIfEnabled = $isScopingEnabled ? (OTelDistroScoperConfig::PREFIX . '\\') : '';

require $otelDistroDir . DIRECTORY_SEPARATOR . 'ProdPhpDir.php';
/**
 * @noinspection PhpFullyQualifiedNameUsageInspection
 * @var class-string<\OpenTelemetry\Distro\ProdPhpDir> $prodPhpDirClass
 */
$prodPhpDirClass = $scopePrefixIfEnabled . 'OpenTelemetry\\Distro\\ProdPhpDir';
$prodPhpDirClass::$fullPath = $prodPhpDir;

require $otelDistroDir . DIRECTORY_SEPARATOR . 'VendorDir.php';
/**
 * @noinspection PhpFullyQualifiedNameUsageInspection
 * @var class-string<\OpenTelemetry\Distro\VendorDir> $vendorDirClass
 */
$vendorDirClass = $scopePrefixIfEnabled . 'OpenTelemetry\\Distro\\VendorDir';
$vendorDirClass::$fullPath = $vendorDir;

require $otelDistroDir . '/BootstrapStageLoggingClassTrait.php';
require $otelDistroDir . '/Util/HiddenConstructorTrait.php';
require $otelDistroDir . '/VendorCustomizationsInterface.php';
require $otelDistroDir . '/RemoteConfigConsumerInterface.php';
require $otelDistroDir . '/PhpPartFacade.php';
