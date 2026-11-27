--TEST--
Check hook with no pre or post callbacks returns true but does nothing
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
var_dump(\OpenTelemetry\Distro\hook(null, 'helloWorld', null, null));

function helloWorld() {
    var_dump('CALL');
}

helloWorld();
?>
--EXPECT--
bool(true)
string(4) "CALL"
