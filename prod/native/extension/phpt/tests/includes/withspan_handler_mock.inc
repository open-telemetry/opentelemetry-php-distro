<?php
declare(strict_types=1);

// Minimal stub classes so PHP accepts #[WithSpan] / #[SpanAttribute] at runtime.
namespace OpenTelemetry\API\Instrumentation;

#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class WithSpan {
    public function __construct(
        public readonly ?string $span_name = null,
        public readonly ?int    $span_kind = null,
        public readonly array   $attributes = []
    ) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
class SpanAttribute {
    public function __construct(public readonly ?string $name = null) {}
}

// Mock handler called by the native extension instead of the real OTel SDK.
// pre(target, params, class, function, filename, lineno, span_args, attributes)
// post(target, params, retval, exception)
class WithSpanHandler {
    public static function pre(
        mixed   $target,
        array   $params,
        ?string $class,
        string  $function,
        ?string $filename,
        ?int    $lineno,
        array   $spanArgs,
        array   $attributes
    ): void {
        echo "pre:\n";
        if (is_object($target)) {
            echo "  target=object(" . get_class($target) . ")\n";
        } else {
            echo "  target="; var_dump($target);
        }
        echo "  class="; var_dump($class);
        echo "  function="; var_dump($function);
        echo "  span_args="; var_dump($spanArgs);
        echo "  attributes="; var_dump($attributes);
    }

    public static function post(
        mixed       $target,
        array       $params,
        mixed       $retval,
        ?\Throwable $exception
    ): void {
        echo "post:\n";
        echo "  retval="; var_dump($retval);
        if ($exception !== null) {
            echo "  exception=" . get_class($exception) . ": " . $exception->getMessage() . "\n";
        } else {
            echo "  exception="; var_dump($exception);
        }
    }
}

// The native extension calls the scoped version when scoped_deps_enabled=true (default).
// Alias the unscoped class into the scoped namespace so both names resolve to the same handler.
$_otelScopedPrefix = getenv('OTEL_PHP_SCOPER_PREFIX') ?: 'OTelDistroScoped';
class_alias(
    'OpenTelemetry\API\Instrumentation\WithSpanHandler',
    $_otelScopedPrefix . '\\OpenTelemetry\\API\\Instrumentation\\WithSpanHandler'
);
unset($_otelScopedPrefix);
