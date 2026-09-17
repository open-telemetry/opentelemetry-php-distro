--TEST--
instrumentation - user func - args post processing when hook adds beyond exact arg count
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

// Function called with exactly num_args arguments (no pre-existing extra-arg slots).
// Hook adds args beyond num_args — verifies correct frame extension without
// corrupting existing slots.
function userspace($arg1, $arg2, $arg3) {
	echo "* userspace() body\n";
	var_dump(func_get_args());
	return "rv";
}

\OpenTelemetry\Distro\hook(NULL, "userspace", function () {
	return [0 => "replaced_1st", 4 => "added_5th", 5 => "added_6th"];
});

var_dump(userspace("first", "second", "third"));

echo 'Test completed';
?>
--EXPECT--
* userspace() body
array(6) {
  [0]=>
  string(12) "replaced_1st"
  [1]=>
  string(6) "second"
  [2]=>
  string(5) "third"
  [3]=>
  NULL
  [4]=>
  string(9) "added_5th"
  [5]=>
  string(9) "added_6th"
}
string(2) "rv"
Test completed
