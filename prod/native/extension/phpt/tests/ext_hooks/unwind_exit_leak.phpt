--TEST--
Test UnwindExit in post handler does not leak otel_exception_state memory
--XFAIL--
exit() in a post hook of a function that threw is not honoured: the pending exception is reported as an uncaught fatal error
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php

use function OpenTelemetry\Distro\hook;

function throwingFunction() {
    throw new Exception();
}

hook(
    null,
    'throwingFunction',
    static fn() => null,
    static function() {
        exit;
    }
);


throwingFunction();
echo "fail\n";
?>

--EXPECT--
