--TEST--
Setting configuration options to non-default value (in this case using ini file)
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
opentelemetry_distro.enabled=0
opentelemetry_distro.log_file=non-default_log_file_value.txt
opentelemetry_distro.log_level=CRITICAL
opentelemetry_distro.log_level_file=TRACE
opentelemetry_distro.log_level_syslog=TRACE
opentelemetry_distro.log_level_win_sys_debug=CRITICAL
opentelemetry_distro.secret_token=non-default_secret_token_123
opentelemetry_distro.server_url=https://non-default_server_url:4321/some/path
opentelemetry_distro.service_name=Non-default Service Name
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

//////////////////////////////////////////////
///////////////  enabled

phptAssertEqual("ini_get('opentelemetry_distro.enabled')", ini_get('opentelemetry_distro.enabled'), false);

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('enabled')",\OpenTelemetry\Distro\get_config_option_by_name('enabled'), false);

phptAssertSame("\OpenTelemetry\Distro\is_enabled()", \OpenTelemetry\Distro\is_enabled(), false);

//////////////////////////////////////////////
///////////////  log_file

phptAssertSame("ini_get('opentelemetry_distro.log_file')", ini_get('opentelemetry_distro.log_file'), 'non-default_log_file_value.txt');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_file'), 'non-default_log_file_value.txt');

//////////////////////////////////////////////
///////////////  log_level

phptAssertSame("ini_get('opentelemetry_distro.log_level')", ini_get('opentelemetry_distro.log_level'), 'CRITICAL');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level')",\OpenTelemetry\Distro\get_config_option_by_name('log_level'), OTEL_PHP_LOG_LEVEL_CRITICAL);

//////////////////////////////////////////////
///////////////  log_level_file

phptAssertSame("ini_get('opentelemetry_distro.log_level_file')", ini_get('opentelemetry_distro.log_level_file'), 'TRACE');

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_file'), OTEL_PHP_LOG_LEVEL_TRACE);

//////////////////////////////////////////////
///////////////  log_level_syslog

if ( ! phptIsOsWindows()) {
    phptAssertSame("ini_get('opentelemetry_distro.log_level_syslog')", ini_get('opentelemetry_distro.log_level_syslog'), 'TRACE');

    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_syslog')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_syslog'), OTEL_PHP_LOG_LEVEL_TRACE);
}

//////////////////////////////////////////////
///////////////  log_level_win_sys_debug

if (phptIsOsWindows()) {
    phptAssertSame("ini_get('opentelemetry_distro.log_level_win_sys_debug')", ini_get('opentelemetry_distro.log_level_win_sys_debug'), 'CRITICAL');
    phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_level_win_sys_debug')",\OpenTelemetry\Distro\get_config_option_by_name('log_level_win_sys_debug'), OTEL_PHP_LOG_LEVEL_CRITICAL);
}

echo 'Test completed'
?>
--EXPECT--
Test completed
