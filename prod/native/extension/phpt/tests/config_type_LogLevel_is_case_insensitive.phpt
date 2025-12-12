--TEST--
Configuration values of type LogLevel are case insensitive
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
OTEL_PHP_LOG_LEVEL=warning
OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG=TRaCe
--INI--
opentelemetry_distro.log_level_syslog=INFO
opentelemetry_distro.log_level_file=dEbUg
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level')",\OpenTelemetry\Distro\get_config_option_by_name('log_level'), OTEL_PHP_LOG_LEVEL_WARNING);

if ( ! phptIsOsWindows()) {
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_syslog')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_syslog'), OTEL_PHP_LOG_LEVEL_INFO);
}

if (phptIsOsWindows()) {
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_win_sys_debug')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_win_sys_debug'), OTEL_PHP_LOG_LEVEL_TRACE);
}

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_file'), OTEL_PHP_LOG_LEVEL_DEBUG);

echo 'Test completed'
?>
--EXPECT--
Test completed
