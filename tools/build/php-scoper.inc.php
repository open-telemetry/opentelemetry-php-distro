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

// Inject autoload-priming for hook() class arguments in auto-instrumentation files.
//
// PhpPartFacade::bootstrap processes the scoped vendor's autoload_files - each instrumentation's _register.php in
// turn calls hook(SomeClass::class, 'method', ...). `SomeClass::class` resolves to a string literal - it does NOT trigger autoload of SomeClass.
// The native instrumentFunction then looks up the class name in EG(class_table) and bails if the class isn't loaded yet.
//
// Walk each hook(CLASS::class, ...) and prepend a class_exists / interface_exists call so the SPL autoloader chain actually loads the class file before the
// hook is registered. We deliberately do NOT trigger autoload from native code - doing so from instrumentFunction had request-shutdown side effects.

$primeAutoloadBeforeHook = static function (string $filePath, string $scoperPrefix, string $content): string {
    // Only patch upstream auto-instrumentation packages.
    if (!str_contains($filePath, '/opentelemetry-auto-')) {
        return $content;
    }
    // Match hook(<class-ref>::class, 'method', ...). <class-ref> can be a bare
    // class name (from `use`), or a single/double-backslash FQCN. We capture it
    // and emit a forced autoload right before the hook call.
    $pattern = '/(?<indent>^[ \t]*)hook\(\s*(?<class>(?:\\\\?[A-Za-z_][A-Za-z0-9_]*)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::class\s*,/m';
    return preg_replace_callback(
        $pattern,
        static function (array $m): string {
            $indent = $m['indent'];
            $class = $m['class'];
            // class_exists() / interface_exists() default to $autoload=true, so
            // referencing the class via ::class then calling these triggers the
            // SPL autoloader chain. The short-circuit handles classes vs interfaces.
            $prime = '\\class_exists(' . $class . '::class) || \\interface_exists(' . $class . '::class);';
            return $indent . $prime . "\n" . $indent . 'hook(' . $class . '::class,';
        },
        $content
    ) ?? $content;
};

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
    // User-facing namespaces hooked by auto-instrumentation must NOT be prefixed.
    // expose-namespaces (which emits class_alias) breaks PHP's method-signature
    // compatibility checks — when user code implements an aliased interface, PHP
    // compares the literal FQCN strings on types and rejects the implementation
    // (e.g. GuzzleHttp\Client::sendRequest(Psr\Http\Message\RequestInterface)
    // vs OTelDistroScoped\Psr\Http\Client\ClientInterface::sendRequest(
    // OTelDistroScoped\Psr\Http\Message\RequestInterface)).
    // With exclude-namespaces, scoper leaves these references untouched so the
    // distro's auto-instrumentation files reference the unprefixed user-vendor
    // interfaces directly. Distro ships its own copy in the scoped vendor under
    // the unprefixed namespace too; composer classmap resolves the duplicate.
    'exclude-namespaces' => [
        'Psr\\Http\\Client',
        'Psr\\Http\\Message',
        'Http\\Client',
        'Http\\Promise',
    ],
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
        $primeAutoloadBeforeHook,
    ],
];
