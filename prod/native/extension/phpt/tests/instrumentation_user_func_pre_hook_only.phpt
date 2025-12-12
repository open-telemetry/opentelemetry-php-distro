--TEST--
instrumentation - user func - pre hook only
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=info
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);



function userspace($arg1, $arg2, $arg3) {
	echo "* userspace() called.\n";
}

\OpenTelemetry\Distro\hook(NULL, "userspace", function () {
	echo "*** prehook userspace()\n";
}, NULL);

userspace("first", 2, 3);

echo "Test completed\n";
?>
--EXPECTF--
*** prehook userspace()
* userspace() called.
Test completed