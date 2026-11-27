--TEST--
Check that a trait method used by classes with different WithSpan attributes keeps each attribute
--DESCRIPTION--
The copies of a trait method in the using classes share their opcodes but have their own scope, here one of them inherits #[WithSpan] from an interface and the other has none.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip requires PHP >= 8.1"); ?>
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

class WithSpanHandler
{
    public static function pre($object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno, array $spanArgs, array $attributes): void
    {
        echo json_encode([get_class($object), $spanArgs]), "\n";
    }

    public static function post(): void
    {
    }
}

interface Spanned
{
    #[WithSpan('from.interface')]
    public function foo(): void;
}

trait Foo
{
    public function foo(): void
    {
    }
}

class A implements Spanned
{
    use Foo;
}

class B
{
    use Foo;
}

class C implements Spanned
{
    use Foo;
}

(new A())->foo();
(new B())->foo();
(new A())->foo();
(new C())->foo();
(new B())->foo();
(new C())->foo();
?>
--EXPECT--
["OpenTelemetry\\API\\Instrumentation\\A",{"name":"from.interface"}]
["OpenTelemetry\\API\\Instrumentation\\A",{"name":"from.interface"}]
["OpenTelemetry\\API\\Instrumentation\\C",{"name":"from.interface"}]
["OpenTelemetry\\API\\Instrumentation\\C",{"name":"from.interface"}]
