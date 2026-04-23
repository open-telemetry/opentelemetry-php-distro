<?php

declare(strict_types=1);

use OpenTelemetry\Distro\OTelDistroScoperConfig;

/*
 * Directory Layout after package install
 *
 *          bootstrap_php_part.php
 *          vendor_81/
 *              OTelDistroScoped/
 *                  Contrib/
 *                  Distro/
 *                  Instrumentation/
 */

$vendorDir = __DIR__ . DIRECTORY_SEPARATOR . 'vendor_' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
require __DIR__ . DIRECTORY_SEPARATOR . 'ScoperConfig.php';
$scopedDistroRootDir = $vendorDir . DIRECTORY_SEPARATOR . OTelDistroScoperConfig::DISTRO_PATH;
$otelDistroDir = $scopedDistroRootDir . DIRECTORY_SEPARATOR . 'Distro';
/** @noinspection PhpFullyQualifiedNameUsageInspection */
$scopePrefixIfEnabled = \OpenTelemetry\Distro\get_config_option_by_name('debug_scoper_enabled') ? (OTelDistroScoperConfig::PREFIX . '\\') : '';

/**
 * @noinspection PhpFullyQualifiedNameUsageInspection
 * @var class-string<\OpenTelemetry\Distro\ProdPhpDir> $prodPhpDirClass
 */
$prodPhpDirClass = $scopePrefixIfEnabled . 'OpenTelemetry\\Distro\\ProdPhpDir';
require $otelDistroDir . DIRECTORY_SEPARATOR . 'ProdPhpDir.php';
$prodPhpDirClass::$fullPath = $scopedDistroRootDir;

/**
 * @noinspection PhpFullyQualifiedNameUsageInspection
 * @var class-string<\OpenTelemetry\Distro\VendorDir> $vendorDirClass
 */
$vendorDirClass = $scopePrefixIfEnabled . 'OpenTelemetry\\Distro\\VendorDir';
require $otelDistroDir . DIRECTORY_SEPARATOR . 'VendorDir.php';
$vendorDirClass::$fullPath = $vendorDir;

require $otelDistroDir . '/BootstrapStageLoggingClassTrait.php';
require $otelDistroDir . '/Util/HiddenConstructorTrait.php';
require $otelDistroDir . '/VendorCustomizationsInterface.php';
require $otelDistroDir . '/RemoteConfigConsumerInterface.php';
require $otelDistroDir . '/PhpPartFacade.php';
