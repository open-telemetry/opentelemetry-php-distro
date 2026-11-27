--TEST--
Check that WithSpan applies when the function is called through a copy of it
--DESCRIPTION--
$obj->method(...), Closure::fromCallable() and function(...) call a copy of the function that shares the run-time cache of the original, so the observer handlers are chosen only once for all of them.
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

class Svc
{
    #[WithSpan('svc.m')]
    public function m(#[SpanAttribute] string $x): void
    {
    }
}

#[WithSpan('fn.f')]
function f(#[SpanAttribute] string $y): void
{
}

$s = new Svc();
$s->m('direct');
$fcc = $s->m(...);
$fcc('first-class callable');
$from = \Closure::fromCallable([$s, 'm']);
$from('fromCallable');
$s->m('direct again');

f('direct');
$ffcc = f(...);
$ffcc('first-class callable');
f('direct again');
?>
--EXPECT--
[{"name":"svc.m"},{"x":"direct"}]
[{"name":"svc.m"},{"x":"first-class callable"}]
[{"name":"svc.m"},{"x":"fromCallable"}]
[{"name":"svc.m"},{"x":"direct again"}]
[{"name":"fn.f"},{"y":"direct"}]
[{"name":"fn.f"},{"y":"first-class callable"}]
[{"name":"fn.f"},{"y":"direct again"}]
