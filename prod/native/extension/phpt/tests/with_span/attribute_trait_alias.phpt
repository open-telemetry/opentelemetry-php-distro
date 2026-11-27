--TEST--
Check that a trait alias does not erase the metadata of the original trait method
--DESCRIPTION--
use T { foo as bar; } copies the method twice with the same opcodes and in the same class, only the name differs. Here only foo gets #[WithSpan], from an interface.
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
        echo $function, ' ', json_encode($spanArgs), "\n";
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

trait T
{
    public function foo(): void
    {
    }
}

class A implements Spanned
{
    use T { foo as bar; }
}

$a = new A();
$a->foo();
$a->bar();
$a->foo();
$a->bar();
$a->foo();
?>
--EXPECT--
foo {"name":"from.interface"}
foo {"name":"from.interface"}
foo {"name":"from.interface"}
