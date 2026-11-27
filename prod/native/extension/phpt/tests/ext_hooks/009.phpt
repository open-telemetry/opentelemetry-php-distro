--TEST--
Check if pre hook can modify not provided arguments
--XFAIL--
CRASH (SIGSEGV): pre hook returning args for parameters that were not passed by the caller
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', fn() => [1 => 'b']);

function helloWorld($a = null, $b = null) {
    var_dump($a, $b);
}

helloWorld();
?>
--EXPECT--
NULL
string(1) "b"
