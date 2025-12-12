--TEST--
instrumentation - user func - return value replacement with type change
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

function userspace($arg1, $arg2, $arg3) {
	echo "* userspace() body.\n";
	return "userspace_rv";
}

\OpenTelemetry\Distro\hook(NULL, "userspace", NULL, function () : int {
	echo "*** posthook userspace()\n";
	return 1234;
});

var_dump(userspace("first", 2, 3));

echo "Test completed\n";
?>
--EXPECTF--
* userspace() body.
*** posthook userspace()
int(1234)
Test completed