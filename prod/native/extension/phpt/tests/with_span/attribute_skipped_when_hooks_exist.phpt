--TEST--
Check what happens when a WithSpan function also has a manual hook()
--DESCRIPTION--
opentelemetry-php-instrumentation skips the WithSpan handler when a manual hook exists for the same function. Distro runs both, so this test pins the distro behaviour.
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
namespace OpenTelemetry\API\Instrumentation {
    include dirname(__DIR__) . '/mocks/WithSpan.php';

    class WithSpanHandler
    {
        public static function pre(): void
        {
            var_dump('withspan_pre');
        }
        public static function post(): void
        {
            var_dump('withspan_post');
        }
    }
}

namespace {
    use OpenTelemetry\API\Instrumentation\WithSpan;

    \OpenTelemetry\Distro\hook(null, 'foo',
        fn() => var_dump('manual_pre'),
        fn() => var_dump('manual_post')
    );

    #[WithSpan]
    function foo(): void
    {
        var_dump('test');
    }

    foo();
}
?>
--EXPECT--
string(10) "manual_pre"
string(12) "withspan_pre"
string(4) "test"
string(13) "withspan_post"
string(11) "manual_post"
