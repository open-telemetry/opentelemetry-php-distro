--TEST--
When value in ini is invalid, extension parses it the same way as PHP - returns false and not returning environment variable
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
opentelemetry_distro.enabled=not a valid bool
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

// according to ini parser in PHP everything except "yes, true, on" values are returned as return atoi(string) !=0 - so it always returns false

var_dump(\OpenTelemetry\Distro\get_config_option_by_name('enabled'));
var_dump(ini_get('opentelemetry_distro.enabled'));

echo 'Test completed'
?>
--EXPECT--
bool(false)
string(16) "not a valid bool"
Test completed
