--TEST--
instrumentation - spl_autoload_register
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

\OpenTelemetry\Distro\hook(NULL, "spl_autoload_register", function () {
	echo "*** prehook()\n";
 }, function () {
	echo "*** posthook()\n";
});





spl_autoload_register(function () { }, true, false);

echo "Test completed\n";
?>
--EXPECTF--
*** prehook()
*** posthook()
Test completed