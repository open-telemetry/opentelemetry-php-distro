--TEST--
Check hook with only pre callback (null post)
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', fn() => var_dump('PRE'), null);

function helloWorld() {
    var_dump('CALL');
}

helloWorld();
?>
--EXPECT--
string(3) "PRE"
string(4) "CALL"
