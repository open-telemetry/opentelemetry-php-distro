--TEST--
instrumentation - internal func - adding more arguements than we can
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
 	echo "args:\n";
	var_dump(func_get_args());
  return [0 => "new haystack", 1 => "new needle", 2 => "we can't do that!"];
 }, function () {
	echo "*** posthook()\n";
 	echo "args:\n";
	var_dump(func_get_args());
});




var_dump(str_contains("something obs", "obs"));

echo "Test completed\n";
?>
--EXPECTF--
%astr_contains() expects exactly 2 arguments, 3 given%a