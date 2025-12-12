--TEST--
Setting configuration options to non-default value (in this case using environment variables)
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
OTEL_PHP_ENABLED=0
OTEL_PHP_LOG_FILE=non-default_log_file_value.txt
OTEL_PHP_LOG_LEVEL=CRITICAL
OTEL_PHP_LOG_LEVEL_FILE=TRACE
OTEL_PHP_LOG_LEVEL_SYSLOG=TRACE
OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG=CRITICAL
OTEL_PHP_SECRET_TOKEN=non-default_secret_token_123
OTEL_PHP_SERVER_URL=https://non-default_server_url:4321/some/path
OTEL_PHP_SERVICE_NAME=Non-default Service Name
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

//////////////////////////////////////////////
///////////////  enabled

phptAssertSame("getenv('OTEL_PHP_ENABLED')", getenv('OTEL_PHP_ENABLED'), '0');

phptAssertSame("\OpenTelemetry\Distro\is_enabled()", \OpenTelemetry\Distro\is_enabled(), false);

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('enabled')",\OpenTelemetry\Distro\get_config_option_by_name('enabled'), false);

//////////////////////////////////////////////
///////////////  log_file

phptAssertSame("getenv('OTEL_PHP_LOG_FILE')", getenv('OTEL_PHP_LOG_FILE'), 'non-default_log_file_value.txt');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_file'), 'non-default_log_file_value.txt');

//////////////////////////////////////////////
///////////////  log_level

phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL')", getenv('OTEL_PHP_LOG_LEVEL'), 'CRITICAL');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level')",\OpenTelemetry\Distro\get_config_option_by_name('log_level'), OTEL_PHP_LOG_LEVEL_CRITICAL);

//////////////////////////////////////////////
///////////////  log_level_file

phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL_FILE')", getenv('OTEL_PHP_LOG_LEVEL_FILE'), 'TRACE');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_file'), OTEL_PHP_LOG_LEVEL_TRACE);

//////////////////////////////////////////////
///////////////  log_level_syslog

phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL_SYSLOG')", getenv('OTEL_PHP_LOG_LEVEL_SYSLOG'), 'TRACE');

if ( ! phptIsOsWindows()) {
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_syslog')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_syslog'), OTEL_PHP_LOG_LEVEL_TRACE);
}

//////////////////////////////////////////////
///////////////  log_level_win_sys_debug

phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG')", getenv('OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG'), 'CRITICAL');

if (phptIsOsWindows()) {
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_win_sys_debug')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_win_sys_debug'), OTEL_PHP_LOG_LEVEL_CRITICAL);
}

echo 'Test completed'
?>
--EXPECT--
Test completed
