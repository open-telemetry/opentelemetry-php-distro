--TEST--
Configuration values of type LogLevel: it is enough to provide unambiguous prefix
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
OTEL_PHP_LOG_LEVEL=warn
OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG=TRa
--XFAIL--
Expected to fail - this is a strange feature - a path to abuse and ambiguity - NOT IMPLEMENTED
--INI--
opentelemetry_distro.log_level_syslog=Er
opentelemetry_distro.log_level_file=dEb
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level')",\OpenTelemetry\Distro\get_config_option_by_name('log_level'), OTEL_PHP_LOG_LEVEL_WARNING);

if ( ! phptIsOsWindows()) {
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_syslog')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_syslog'), OTEL_PHP_LOG_LEVEL_ERROR);
}

if (phptIsOsWindows()) {
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_win_sys_debug')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_win_sys_debug'), OTEL_PHP_LOG_LEVEL_TRACE);
}

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_file'), OTEL_PHP_LOG_LEVEL_DEBUG);

echo 'Test completed'
?>
--EXPECT--
Test completed
