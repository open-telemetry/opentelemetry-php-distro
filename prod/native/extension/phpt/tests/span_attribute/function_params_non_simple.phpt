--TEST--
Check if function non-simple types are ignored
--XFAIL--
values that are not valid OTel attribute values (null, objects, closures) are not filtered out
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
include dirname(__DIR__) . '/mocks/WithSpanHandlerDumpAttributes.php';
use OpenTelemetry\API\Instrumentation\WithSpan;
use OpenTelemetry\API\Instrumentation\SpanAttribute;

#[WithSpan]
function foo(
    #[SpanAttribute] array $one,
    #[SpanAttribute] object $two,
    #[SpanAttribute] callable $three,
    #[SpanAttribute] $four,
): void
{
    var_dump('foo');
}

foo(
    one: ['foo' => 'bar'],
    two: new \stdClass(),
    three: function(){return 'fn';},
    four: null,
);
?>
--EXPECTF--
string(3) "pre"
array(1) {
  ["one"]=>
  array(1) {
    ["foo"]=>
    string(3) "bar"
  }
}
string(3) "foo"
string(4) "post"