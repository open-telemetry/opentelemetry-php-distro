--TEST--
Boolean configuration option value 'no' (in this case using ini file) should be interpreted as false and it should be case insensitive
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=ERROR
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
opentelemetry_distro.enabled=No
--FILE--
<?php
declare(strict_types=1);

var_dump(ini_get('opentelemetry_distro.enabled'));
var_dump(\OpenTelemetry\Distro\is_enabled());

echo 'Test completed'
?>
--EXPECT--
string(0) ""
bool(false)
Test completed
