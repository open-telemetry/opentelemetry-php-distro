--TEST--
Check that WithSpan on a method still runs when the method is also hooked
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP >= 8.1'); ?>
--DESCRIPTION--
opentelemetry-php-instrumentation applies the WithSpan handler only if no manual hook exists for the method. Distro runs both: manual pre, WithSpan pre, the method, WithSpan post, manual post.
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
        var_dump('withspan pre');
    }
    public static function post(): void
    {
        var_dump('withspan post');
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
string(12) "withspan pre"
string(3) "foo"
string(13) "withspan post"
string(4) "post"