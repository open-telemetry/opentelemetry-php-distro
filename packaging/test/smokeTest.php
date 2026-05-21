<?php

const CRED = "\033[31m";
const CGREEN = "\033[32m";
const CDEF = "\033[39m";

echo CGREEN."Starting package smoke test\n".CDEF;

$scopeName = isset($argv[1]) ? $argv[1] . "\\" : "";

echo "Checking if extension is loaded: ";
if (array_search("opentelemetry_distro", get_loaded_extensions()) === false) {
    echo CRED."FAILED. OpenTelemetry PHP Distro extension not found\n".CDEF;
    exit(1);
}
echo CGREEN."OK\n".CDEF;

echo "Looking for internal function 'OpenTelemetry\Distro\is_enabled': ";
if (array_search("OpenTelemetry\Distro\is_enabled", get_extension_funcs("opentelemetry_distro")) === false) {
    echo CRED."FAILED. OpenTelemetry PHP Distro extension function 'OpenTelemetry\Distro\is_enabled' not found\n".CDEF;
    exit(1);
}
echo CGREEN."OK\n".CDEF;


echo "Checking if extension is enabled: ";
/** @noinspection PhpFullyQualifiedNameUsageInspection */
if (\OpenTelemetry\Distro\is_enabled() !== true) {
    echo CRED."FAILED. OpenTelemetry PHP Distro extension is not enabled\n".CDEF;
    exit(1);
}
echo CGREEN."OK\n".CDEF;

echo "Looking for {$scopeName}OpenTelemetry\Distro\PhpPartFacade class: ";
if (array_search("{$scopeName}OpenTelemetry\Distro\PhpPartFacade", get_declared_classes()) === false) {
    echo CRED."FAILED. {$scopeName}OpenTelemetry\Distro\PhpPartFacade class not found. Bootstrap failed\n".CDEF;
    exit(1);
}
echo CGREEN."OK\n".CDEF;

echo "Trying to log something to stderr: ";
/**
 * @var class-string<\OpenTelemetry\Distro\Log\LogBackend> $logBackendClass
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */
$logBackendClass = "{$scopeName}OpenTelemetry\\Distro\\Log\\LogBackend";
/**
 * @var class-string<\OpenTelemetry\Distro\Log\LogFeature> $logFeatureClass
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */
$logFeatureClass = "{$scopeName}OpenTelemetry\\Distro\\Log\\LogFeature";
/**
 * @var class-string<\OpenTelemetry\Distro\Log\LogLevel> $logLevelClass
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */
$logLevelClass = "{$scopeName}OpenTelemetry\\Distro\\Log\\LogLevel";
$logBackendClass::getSingletonInstance()->write(
    file: __FILE__,
    line: __LINE__,
    func: __FUNCTION__,
    featureOrCategory: $logFeatureClass::BOOTSTRAP,
    level: $logLevelClass::off,
    message: 'This is just a dummy message to test production code logging',
    context: ['dummy ctx key' => 'dummy ctx value'],
    isForced: true
);
echo CGREEN."OK\n".CDEF;

echo CGREEN."Smoke test passed\n".CDEF;
