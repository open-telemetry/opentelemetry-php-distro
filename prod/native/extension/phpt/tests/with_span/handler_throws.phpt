--TEST--
Check that an exception thrown by the WithSpan handlers does not reach the application
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
    public static function pre(): void
    {
        throw new \RuntimeException('pre');
    }

    public static function post(): void
    {
        throw new \RuntimeException('post');
    }
}

#[WithSpan]
function foo(): void
{
    echo "body\n";
}

foo();
foo();
echo "after\n";
?>
--EXPECT--
body
body
after
