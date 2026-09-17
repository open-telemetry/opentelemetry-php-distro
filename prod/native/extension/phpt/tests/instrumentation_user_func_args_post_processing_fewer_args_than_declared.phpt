--TEST--
instrumentation - user func - args post processing when hook targets CV slots below effectiveBase
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

// Function with optional params, called with fewer args than last_var.
// Hook targets slot index 1 (a CV slot, within effectiveBase = max(1, 3) = 3).
// Condition highestArgIdx+1=2 > effectiveBase=3 is FALSE, so NO frame extension
// occurs and ZEND_CALL_NUM_ARGS stays at 1.
// ZEND_RECV_INIT runs after our hook and resets $arg2/$arg3 to their defaults,
// so the hook values are not visible — but crucially: no crash occurs.
function userspace($arg1 = "def1", $arg2 = "def2", $arg3 = "def3") {
	echo "* userspace() body\n";
	var_dump(func_get_args());
	echo "arg2="; var_dump($arg2);
	return "rv";
}

\OpenTelemetry\Distro\hook(NULL, "userspace", function () {
	return [1 => "hook_2nd"];
});

var_dump(userspace("only"));

echo 'Test completed';
?>
--EXPECT--
* userspace() body
array(1) {
  [0]=>
  string(4) "only"
}
arg2=string(4) "def2"
string(2) "rv"
Test completed
