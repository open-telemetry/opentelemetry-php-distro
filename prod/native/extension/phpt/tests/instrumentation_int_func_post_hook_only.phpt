--TEST--
instrumentation - internal func post hook only
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

\OpenTelemetry\Distro\hook(NULL, "str_contains", NULL, function () {
	echo "*** posthook()\n";
});

var_dump(str_contains("abcdefg obs", "obs"));

echo "Test completed\n";
?>
--EXPECTF--
*** posthook()
bool(true)
Test completed