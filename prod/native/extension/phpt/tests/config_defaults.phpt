--TEST--
Verify configuration option's defaults
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
OTEL_PHP_ENABLED=
OTEL_PHP_LOG_FILE=
OTEL_PHP_LOG_LEVEL=
OTEL_PHP_LOG_LEVEL_FILE=
OTEL_PHP_LOG_LEVEL_SYSLOG=
OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG=
OTEL_PHP_SECRET_TOKEN=
OTEL_PHP_SERVER_URL=
OTEL_PHP_SERVICE_NAME=
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

//////////////////////////////////////////////
///////////////  enabled

phptAssertSame("getenv('OTEL_PHP_ENABLED')", getenv('OTEL_PHP_ENABLED'), false);

phptAssertEqual("ini_get('opentelemetry_distro.enabled')", ini_get('opentelemetry_distro.enabled'), false);

phptAssertSame("\OpenTelemetry\Distro\is_enabled()", \OpenTelemetry\Distro\is_enabled(), true);

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('enabled')",\OpenTelemetry\Distro\get_config_option_by_name('enabled'), true);

//////////////////////////////////////////////
///////////////  log_file

phptAssertSame("getenv('OTEL_PHP_LOG_FILE')", getenv('OTEL_PHP_LOG_FILE'), false);

phptAssertEqual("ini_get('opentelemetry_distro.log_file')", ini_get('opentelemetry_distro.log_file'), false);

phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('log_file')",\OpenTelemetry\Distro\get_config_option_by_name('log_file'), "");

//////////////////////////////////////////////
///////////////  log_level

phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL')", getenv('OTEL_PHP_LOG_LEVEL'), false);

phptAssertEqual("ini_get('opentelemetry_distro.log_level')", ini_get('opentelemetry_distro.log_level'), false);

//////////////////////////////////////////////
///////////////  log_level_file

phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL_FILE')", getenv('OTEL_PHP_LOG_LEVEL_FILE'), false);

phptAssertEqual("ini_get('opentelemetry_distro.log_level_file')", ini_get('opentelemetry_distro.log_level_file'), false);

//////////////////////////////////////////////
///////////////  log_level_syslog

if ( ! phptIsOsWindows()) {
    phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL_SYSLOG')", getenv('OTEL_PHP_LOG_LEVEL_SYSLOG'), false);

    phptAssertEqual("ini_get('opentelemetry_distro.log_level_syslog')", ini_get('opentelemetry_distro.log_level_syslog'), false);

}

//////////////////////////////////////////////
///////////////  log_level_win_sys_debug

if (phptIsOsWindows()) {
    phptAssertSame("getenv('OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG')", getenv('OTEL_PHP_LOG_LEVEL_WIN_SYS_DEBUG'), false);

    phptAssertEqual("ini_get('opentelemetry_distro.log_level_win_sys_debug')", ini_get('opentelemetry_distro.log_level_win_sys_debug'), false);

}

// //////////////////////////////////////////////
// ///////////////  secret_token

// phptAssertSame("getenv('OTEL_PHP_SECRET_TOKEN')", getenv('OTEL_PHP_SECRET_TOKEN'), false);

// phptAssertEqual("ini_get('opentelemetry_distro.secret_token')", ini_get('opentelemetry_distro.secret_token'), false);

// phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('secret_token')",\OpenTelemetry\Distro\get_config_option_by_name('secret_token'), "");

// //////////////////////////////////////////////
// ///////////////  server_url

// phptAssertSame("getenv('OTEL_PHP_SERVER_URL')", getenv('OTEL_PHP_SERVER_URL'), false);

// phptAssertEqual("ini_get('opentelemetry_distro.server_url')", ini_get('opentelemetry_distro.server_url'), false);

// phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('server_url')",\OpenTelemetry\Distro\get_config_option_by_name('server_url'), 'http://localhost:8200');

// //////////////////////////////////////////////
// ///////////////  service_name

// phptAssertSame("getenv('OTEL_PHP_SERVICE_NAME')", getenv('OTEL_PHP_SERVICE_NAME'), false);

// phptAssertEqual("ini_get('opentelemetry_distro.service_name')", ini_get('opentelemetry_distro.service_name'), false);

// phptAssertSame("OpenTelemetry\\Distro\\get_config_option_by_name('service_name')",\OpenTelemetry\Distro\get_config_option_by_name('service_name'), "");

echo 'Test completed'
?>
--EXPECT--
Test completed
