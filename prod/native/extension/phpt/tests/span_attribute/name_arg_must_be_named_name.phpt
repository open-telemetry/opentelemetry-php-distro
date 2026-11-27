--TEST--
Check SpanAttribute name is only taken from a positional or `name:` argument
--DESCRIPTION--
The name is read from the attribute's first argument. A named argument other
than `name:` is not the name, and SpanAttribute::newInstance() would reject it,
so the attribute is dropped rather than keyed on that argument's value.
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

class Subject
{
    #[WithSpan]
    public function m(
        #[SpanAttribute(name: 'named')] string $a,
        #[SpanAttribute(nmae: 'typo')] string $b,
        #[SpanAttribute(name: null)] string $c,
    ): void {
    }
}

(new Subject())->m('A', 'B', 'C');
?>
--EXPECT--
string(3) "pre"
array(2) {
  ["named"]=>
  string(1) "A"
  ["c"]=>
  string(1) "C"
}
string(4) "post"
