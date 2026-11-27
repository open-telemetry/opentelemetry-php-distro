--TEST--
Check if hook returns true
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
$ret = \OpenTelemetry\Distro\hook(null, 'some_function');

var_dump($ret);
?>
--EXPECT--
bool(true)
