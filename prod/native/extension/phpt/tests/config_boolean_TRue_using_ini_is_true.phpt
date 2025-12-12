--TEST--
Boolean configuration option value 'TRue' (in this case using ini file) should be interpreted as true and it should be case insensitive
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
opentelemetry_distro.enabled=TRue
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

phptAssertEqual("ini_get('opentelemetry_distro.enabled')", ini_get('opentelemetry_distro.enabled'), true);

phptAssertSame("\OpenTelemetry\Distro\is_enabled()", \OpenTelemetry\Distro\is_enabled(), true);

echo 'Test completed'
?>
--EXPECT--
Test completed
