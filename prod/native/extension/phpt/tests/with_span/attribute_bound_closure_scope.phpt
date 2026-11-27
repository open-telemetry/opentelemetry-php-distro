--TEST--
Check that WithSpan applies to a closure that was bound to another scope
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP >= 8.1'); ?>
--ENV--
OTEL_PHP_SCOPED_DEPS_ENABLED=false
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock_unscoped.inc
opentelemetry_distro.attr_hooks_enabled = On
--FILE--
<?php
namespace OpenTelemetry\API\Instrumentation;

include dirname(__DIR__) . '/mocks/WithSpan.php';
include dirname(__DIR__) . '/mocks/SpanAttribute.php';

class WithSpanHandler
{
    public static function pre($object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno, array $spanArgs, array $attributes): void
    {
        echo json_encode([$spanArgs, $attributes]), "\n";
    }

    public static function post(): void
    {
    }
}

class Foo
{
}

$fn = #[WithSpan('closure')] function (#[SpanAttribute] string $x) {
};

$fn('unbound');
$bound = \Closure::bind($fn, null, Foo::class);
$bound('bound to Foo');
$fn('unbound again');
$bound('bound again');
?>
--EXPECT--
[{"name":"closure"},{"x":"unbound"}]
[{"name":"closure"},{"x":"bound to Foo"}]
[{"name":"closure"},{"x":"unbound again"}]
[{"name":"closure"},{"x":"bound again"}]
