--TEST--
Boolean configuration option value 1 (in this case using ini file) should be interpreted as true
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
opentelemetry_distro.enabled=1
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

phptAssertSame("ini_get('opentelemetry_distro.enabled')", ini_get('opentelemetry_distro.enabled'), '1');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('enabled')", \OpenTelemetry\Distro\get_config_option_by_name('enabled'), true);

echo 'Test completed'
?>
--EXPECT--
Test completed
