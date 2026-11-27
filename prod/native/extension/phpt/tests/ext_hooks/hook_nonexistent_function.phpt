--TEST--
Check hook on function that never gets defined does not error
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
var_dump(\OpenTelemetry\Distro\hook(null, 'never_defined_function', fn() => var_dump('PRE')));
var_dump('done');
?>
--EXPECT--
bool(true)
string(4) "done"
