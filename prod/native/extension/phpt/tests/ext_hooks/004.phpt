--TEST--
Check if multiple hooks are invoked
--XFAIL--
post hooks run in registration order; instrumentation-ext runs them in reverse (LIFO)
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', fn() => var_dump('PRE_1'), fn() => var_dump('POST_1'));
\OpenTelemetry\Distro\hook(null, 'helloWorld', fn() => var_dump('PRE_2'), fn() => var_dump('POST_2'));

function helloWorld() {
    var_dump('CALL');
}

helloWorld();
?>
--EXPECT--
string(5) "PRE_1"
string(5) "PRE_2"
string(4) "CALL"
string(6) "POST_2"
string(6) "POST_1"
