--TEST--
instrumentation - user method - mixed case hook placed before class is declared fires on first call
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

var_dump(\OpenTelemetry\Distro\hook("TESTCLASS", "USERSPACE", function () {
	echo "*** prehook userspace()\n";
 }, function () {
	echo "*** posthook userspace()\n";
}));

require("includes/test_class.inc");

$obj = new TestClass;

var_dump($obj->userspace("first", 2, 3));

echo "Test completed\n";
?>
--EXPECTF--
bool(true)
*** prehook userspace()
* userspace() body start.
args:
array(3) {
  [0]=>
  string(5) "first"
  [1]=>
  int(2)
  [2]=>
  int(3)
}
* userspace() body end
*** posthook userspace()
string(12) "userspace_rv"
Test completed
