--TEST--
Setting configuration option to invalid value via ini file
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
opentelemetry.enabled=not valid boolean value
opentelemetry.secret_token=\|<>|/
opentelemetry.server_url=<\/\/>
opentelemetry.service_name=/\><\/
extension=/otel/opentelemetry_php_distro.so
opentelemetry.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

//////////////////////////////////////////////
///////////////  enabled

echo "enabled\n";
var_dump(ini_get('opentelemetry.enabled'));
var_dump(\OpenTelemetry\Distro\get_config_option_by_name('enabled'));
var_dump(\OpenTelemetry\Distro\is_enabled());

echo 'Test completed'
?>
--EXPECT--
enabled
string(23) "not valid boolean value"
bool(false)
bool(false)
Test completed
