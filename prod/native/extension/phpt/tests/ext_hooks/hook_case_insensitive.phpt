--TEST--
Check hooks are matched case-insensitively
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'HelloWorld', fn() => var_dump('PRE'), fn() => var_dump('POST'));

function helloworld() {
    var_dump('CALL');
}

helloworld();
?>
--EXPECT--
string(3) "PRE"
string(4) "CALL"
string(4) "POST"
