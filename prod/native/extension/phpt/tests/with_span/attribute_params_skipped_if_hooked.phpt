--TEST--
Check if hooking a method takes priority over WithSpan
--XFAIL--
WithSpan handler is skipped by instrumentation-ext when a manual hook() exists for the function; distro runs both
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP >= 8.1'); ?>
--DESCRIPTION--
Attribute-based hooks are only applied if no other hooks are registered on a function or method.
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
use OpenTelemetry\API\Instrumentation\WithSpan;

class WithSpanHandler
{
    public static function pre(): void
    {
        var_dump('should not be called');
    }
    public static function post(): void
    {
        var_dump('should not be called');
    }
}

class Foo
{
    #[WithSpan()]
    function foo(): void
    {
        var_dump('foo');
    }
}

\OpenTelemetry\Distro\hook(Foo::class, 'foo', function(){var_dump('pre');}, function(){var_dump('post');});

(new Foo())->foo();
?>
--EXPECT--
string(3) "pre"
string(3) "foo"
string(4) "post"