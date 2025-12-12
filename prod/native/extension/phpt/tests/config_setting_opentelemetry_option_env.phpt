--TEST--
Setting OpenTelemetry configuration option using environment variable
--ENV--
OTEL_EXPORTER_OTLP_CERTIFICATE=/path/to/cert.pem
OTEL_PHP_LOG_LEVEL_STDERR=CRITICAL
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);


var_dump(\OpenTelemetry\Distro\get_config_option_by_name('OTEL_EXPORTER_OTLP_CERTIFICATE'));

echo 'Test completed'
?>
--EXPECT--
string(17) "/path/to/cert.pem"
Test completed
