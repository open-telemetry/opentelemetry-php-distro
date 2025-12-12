--TEST--
Configuration in ini file has higher precedence than environment variables
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
OTEL_PHP_LOG_FILE=log_file_from_env_vars.txt
OTEL_PHP_LOG_LEVEL_FILE=off
--INI--
opentelemetry_distro.log_file=log_file_from_ini.txt
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

phptAssertSame("getenv('OTEL_PHP_LOG_FILE')", getenv('OTEL_PHP_LOG_FILE'), 'log_file_from_env_vars.txt');

phptAssertSame("ini_get('opentelemetry_distro.log_file')", ini_get('opentelemetry_distro.log_file'), 'log_file_from_ini.txt');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_file'), 'log_file_from_ini.txt');

echo 'Test completed'
?>
--EXPECT--
Test completed
