--TEST--
Check exit() during SpanAttribute name evaluation is reported, not silent
--DESCRIPTION--
Same unwind-exit case as with_span/attribute_args_exit_in_autoload.phpt, on the
other evaluation path: func_get_args() evaluates the #[SpanAttribute] name arg,
which can reach userland through the autoloader.

As there, the unwind exit cannot be honoured from a begin handler, so the
extension warns and proceeds rather than clearing it with no trace.

The attribute is dropped, not recorded under the parameter name. The author
asked for a specific key and it could not be produced, so there is no key to
record under: emitting "one" instead would publish telemetry that looks
deliberately configured under a name nothing queries. A missing attribute is
diagnosable, a renamed one is not. (A *bare* #[SpanAttribute] is different -
it has no name arg and is defined to key on the parameter name, which
function_params_non_simple.phpt covers.)
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
include dirname(__DIR__) . '/mocks/WithSpan.php';
include dirname(__DIR__) . '/mocks/SpanAttribute.php';
include dirname(__DIR__) . '/mocks/WithSpanHandlerDumpAttributes.php';
use OpenTelemetry\API\Instrumentation\SpanAttribute;
use OpenTelemetry\API\Instrumentation\WithSpan;

spl_autoload_register(function (string $class): void {
    if ($class === 'OpenTelemetry\\Test\\ExitName') {
        echo "autoload: calling exit\n";
        exit;
    }
});

#[WithSpan]
function foo(#[SpanAttribute(\OpenTelemetry\Test\ExitName::ATTR)] string $one): void
{
    var_dump('foo body');
}

foo('value');
var_dump('after');
?>
--EXPECTF--
autoload: calling exit
string(3) "pre"
array(0) {
}
string(8) "foo body"
string(4) "post"
string(5) "after"
