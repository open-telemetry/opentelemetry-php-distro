--TEST--
Setting configuration option to invalid value via environment variables
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
OTEL_PHP_ENABLED=not_valid_boolean_value
OTEL_PHP_ASSERT_LEVEL=|:/:\:|
OTEL_PHP_SECRET_TOKEN=\|<>|/
OTEL_PHP_SERVER_URL=<\/\/>
OTEL_PHP_SERVICE_NAME=/\><\/
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/includes/tests_util.inc';

//////////////////////////////////////////////
///////////////  enabled

echo "enabled\n";
var_dump(getenv('OTEL_PHP_ENABLED'));
var_dump(\OpenTelemetry\Distro\is_enabled());
var_dump(\OpenTelemetry\Distro\get_config_option_by_name('enabled'));

echo 'Test completed'
?>
--EXPECT--
enabled
string(23) "not_valid_boolean_value"
bool(false)
bool(false)
Test completed
