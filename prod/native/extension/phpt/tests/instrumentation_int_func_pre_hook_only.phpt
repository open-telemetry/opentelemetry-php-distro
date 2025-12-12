--TEST--
instrumentation - internal func pre hook only
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

\OpenTelemetry\Distro\hook(NULL, "str_contains", function () {
	echo "*** prehook()\n";
}, NULL);

var_dump(str_contains("abcdefg obs", "obs"));

echo "Test completed\n";
?>
--EXPECTF--
*** prehook()
bool(true)
Test completed