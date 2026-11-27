--TEST--
Check if WithSpan can be applied to a method
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
include dirname(__DIR__) . '/mocks/WithSpanHandler.php';
use OpenTelemetry\API\Instrumentation\WithSpan;

class TestClass
{
    #[WithSpan]
    function sayFoo(): void
    {
        var_dump('foo');
    }
}

$reflection = new \ReflectionMethod(TestClass::class, 'sayFoo');
var_dump($reflection->getAttributes()[0]->getName() == WithSpan::class);

$c = new TestClass();
$c->sayFoo();
?>
--EXPECT--
bool(true)
string(3) "pre"
string(3) "foo"
string(4) "post"