--TEST--
instrumentation - user func - return value replacement only in explicitly specified return type hooks
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
	return 12;
});

\OpenTelemetry\Distro\hook(NULL, "userspace", NULL, function () : mixed {
	echo "*** second posthook userspace()\n";
	return "second_rv";
});

\OpenTelemetry\Distro\hook(NULL, "userspace", NULL, function () {
	echo "*** third posthook userspace()\n";
	return "third_rv";
});

var_dump(userspace("first", 2, 3));

echo "Test completed\n";
?>
--EXPECTF--
* userspace() body.
*** posthook userspace()
*** second posthook userspace()
*** third posthook userspace()
string(9) "second_rv"
Test completed