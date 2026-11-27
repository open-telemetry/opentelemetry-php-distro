--TEST--
Test UnwindExit in post handler does not leak otel_exception_state memory
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
