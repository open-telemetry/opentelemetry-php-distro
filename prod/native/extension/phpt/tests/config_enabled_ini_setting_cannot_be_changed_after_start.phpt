--TEST--
Check that opentelemetry.enabled cannot be set with ini_set()
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

if (ini_set('opentelemetry.enabled', 'new value') === false) {
    echo 'ini_set returned false';
}
?>
--EXPECT--
ini_set returned false
