--TEST--
Boolean configuration option value 0 (in this case using environment variable) should be interpreted as false
--ENV--
OTEL_PHP_ENABLED=0
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

phptAssertSame("getenv('OTEL_PHP_ENABLED')", getenv('OTEL_PHP_ENABLED'), '0');

phptAssertSame('\OpenTelemetry\Distro\is_enabled()', \OpenTelemetry\Distro\is_enabled(), false);

echo 'Test completed'
?>
--EXPECT--
Test completed
