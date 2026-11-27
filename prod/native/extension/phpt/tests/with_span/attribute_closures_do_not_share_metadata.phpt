--TEST--
Check that closures with different WithSpan attributes do not share metadata
--DESCRIPTION--
Before PHP 8.4 every closure is named {closure} and has no class, so a key made of the class and function names is the same for all of them.
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

$a = #[WithSpan('closure.a')] fn(#[SpanAttribute] string $x) => $x;
$b = #[WithSpan('closure.b', 3)] fn(#[SpanAttribute] int $y, #[SpanAttribute('z')] string $w) => $y;
$plain = fn(string $p) => $p;

$a('1');
$b(2, 'two');
$plain('not instrumented');
$a('3');
$b(4, 'four');
$a('5');
?>
--EXPECT--
[{"name":"closure.a"},{"x":"1"}]
[{"name":"closure.b","span_kind":3},{"y":2,"z":"two"}]
[{"name":"closure.a"},{"x":"3"}]
[{"name":"closure.b","span_kind":3},{"y":4,"z":"four"}]
[{"name":"closure.a"},{"x":"5"}]
