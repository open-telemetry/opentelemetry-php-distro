--TEST--
Check an exception thrown during WithSpan attribute arg evaluation is reported
--DESCRIPTION--
Evaluating an IS_CONSTANT_AST arg can run arbitrary userland (an autoloader, a
const-expr "new", __get). Such an exception must not be discarded silently, and
must not leak into the hooked function's frame either.

It cannot be propagated to the caller: the VM runs begin handlers immediately
before entering the observed function and does not check for a pending
exception in between. So the policy is the same one the extension already
applies to an exception thrown by a hook itself - report it as an
E_CORE_WARNING, discard it, and let the observed call proceed with that arg
missing.

The point of this test is that a genuine userland exception is distinguishable
from an unresolvable constant (attribute_args_undefined_constant.phpt): both
skip the arg, but only this one names what went wrong.
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
include dirname(__DIR__) . '/mocks/WithSpanHandlerDumpAttributeArgs.php';
use OpenTelemetry\API\Instrumentation\WithSpan;

spl_autoload_register(function (string $class): void {
    if ($class === 'OpenTelemetry\\Test\\ThrowingKind') {
        throw new \LogicException('autoloader exploded');
    }
});

#[WithSpan('my-span', \OpenTelemetry\Test\ThrowingKind::KIND)]
function foo(): void
{
    var_dump('foo body');
}

try {
    foo();
    var_dump('no exception propagated');
} catch (\Throwable $e) {
    var_dump('caught: ' . get_class($e) . ': ' . $e->getMessage());
}
var_dump('after');
?>
--EXPECTF--
string(3) "pre"
array(1) {
  ["name"]=>
  string(7) "my-span"
}
string(8) "foo body"
string(4) "post"
string(23) "no exception propagated"
string(5) "after"
