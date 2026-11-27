--TEST--
Check that exit() in the WithSpan post handler ends the script
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

register_shutdown_function(static fn() => print("shutdown\n"));

class WithSpanHandler
{
    public static function pre(): void
    {
        echo "pre\n";
    }

    public static function post(): void
    {
        echo "post: exit\n";
        exit;
    }
}

#[WithSpan]
function foo(): void
{
    echo "body\n";
}

foo();
echo "after foo\n";
?>
--EXPECT--
pre
body
post: exit
shutdown
