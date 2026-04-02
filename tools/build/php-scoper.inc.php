<?php

declare(strict_types=1);

$prefix = getenv('OTEL_PHP_SCOPER_PREFIX');
if (!is_string($prefix) || $prefix === '') {
    $prefix = 'OTelDistroScoped';
}

$extensionFunctionFqcns = [
    'OpenTelemetry\\Distro\\log_feature',
    'OpenTelemetry\\Distro\\get_config_option_by_name',
    'OpenTelemetry\\Distro\\hook',
    'OpenTelemetry\\Distro\\get_remote_configuration',
    'OpenTelemetry\\Distro\\is_enabled',
    'OpenTelemetry\\Distro\\OtlpExporters\\convert_spans',
    'OpenTelemetry\\Distro\\OtlpExporters\\convert_logs',
    'OpenTelemetry\\Distro\\OtlpExporters\\convert_metrics',
    'OpenTelemetry\\Distro\\HttpTransport\\initialize',
    'OpenTelemetry\\Distro\\HttpTransport\\enqueue',
    'OpenTelemetry\\Distro\\InferredSpans\\force_set_object_property_value'
];

$restoreUnscopedExtensionFunctions = static function (string $filePath, string $scoperPrefix, string $content) use ($extensionFunctionFqcns): string {
    $content = str_replace($scoperPrefix . '\\' . $scoperPrefix . '\\', $scoperPrefix . '\\', $content);

    // Keep OpenTelemetry\Instrumentation\hook scoped in vendor packages.
    // We only unscope native extension functions from OpenTelemetry\Distro below.
    $scopedInstrumentationHook = $scoperPrefix . '\\OpenTelemetry\\Instrumentation\\hook';
    $content = str_replace(
        'use function OpenTelemetry\\Instrumentation\\hook;',
        'use function ' . $scopedInstrumentationHook . ';',
        $content
    );
    $content = str_replace(
        '\\OpenTelemetry\\Instrumentation\\hook(',
        '\\' . $scopedInstrumentationHook . '(',
        $content
    );

    // This script is used for two scoper runs; only rewrite our own distro sources.
    if (!str_contains($filePath, '/prod/php/OpenTelemetry/')) {
        return $content;
    }

    $prefixedRoot = $scoperPrefix . '\\';
    foreach ($extensionFunctionFqcns as $functionFqcn) {
        $content = str_replace($prefixedRoot . $functionFqcn, $functionFqcn, $content);
    }

    return $content;
};

return [
    'prefix' => $prefix,
    'exclude-functions' => [
        'OpenTelemetry\\Distro\\log_feature',
        'OpenTelemetry\\Distro\\get_config_option_by_name',
        'OpenTelemetry\\Distro\\hook',
        'OpenTelemetry\\Distro\\get_remote_configuration',
        'OpenTelemetry\\Distro\\is_enabled',
        'OpenTelemetry\\Distro\\OtlpExporters\\convert_spans',
        'OpenTelemetry\\Distro\\OtlpExporters\\convert_logs',
        'OpenTelemetry\\Distro\\OtlpExporters\\convert_metrics',
        'OpenTelemetry\\Distro\\HttpTransport\\initialize',
        'OpenTelemetry\\Distro\\HttpTransport\\enqueue',
        'OpenTelemetry\\Distro\\InferredSpans\\force_set_object_property_value'
    ],
    'expose-functions' => [
        'OpenTelemetry\\Distro\\log_feature',
        'OpenTelemetry\\Distro\\get_config_option_by_name',
        'OpenTelemetry\\Distro\\hook',
        'OpenTelemetry\\Distro\\get_remote_configuration',
        'OpenTelemetry\\Distro\\is_enabled',
        'OpenTelemetry\\Distro\\OtlpExporters\\convert_spans',
        'OpenTelemetry\\Distro\\OtlpExporters\\convert_logs',
        'OpenTelemetry\\Distro\\OtlpExporters\\convert_metrics',
        'OpenTelemetry\\Distro\\HttpTransport\\initialize',
        'OpenTelemetry\\Distro\\HttpTransport\\enqueue',
        'OpenTelemetry\\Distro\\InferredSpans\\force_set_object_property_value'
    ],
    'patchers' => [
        $restoreUnscopedExtensionFunctions,
    ],
];
