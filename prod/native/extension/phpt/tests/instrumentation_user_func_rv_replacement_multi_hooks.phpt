--TEST--
instrumentation - user func - return value replacement in multiple hooks - post hooks run in reverse order, first registered takes effect
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

function userspace($arg1, $arg2, $arg3) : string {
	echo "* userspace() body.\n";
	return "userspace_rv";
}

\OpenTelemetry\Distro\hook(NULL, "userspace", function () {
	echo "*** prehook userspace()\n";
 }, function () : mixed {
	echo "*** posthook userspace()\n";
	return "first_rv";
});

\OpenTelemetry\Distro\hook(NULL, "userspace", function () {
	echo "*** second prehook userspace()\n";
 }, function () : mixed {
	echo "*** second posthook userspace()\n";
	return "second_rv";
});

\OpenTelemetry\Distro\hook(NULL, "userspace", function () {
	echo "*** third prehook userspace()\n";
 }, function () : mixed {
	echo "*** third posthook userspace()\n";
	return "third_rv";
});

var_dump(userspace("first", 2, 3));

echo "Test completed\n";
?>
--EXPECTF--
*** prehook userspace()
*** second prehook userspace()
*** third prehook userspace()
* userspace() body.
*** third posthook userspace()
*** second posthook userspace()
*** posthook userspace()
string(8) "first_rv"
Test completed