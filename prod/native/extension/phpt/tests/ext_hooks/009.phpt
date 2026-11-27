--TEST--
Check if pre hook can modify not provided arguments
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
