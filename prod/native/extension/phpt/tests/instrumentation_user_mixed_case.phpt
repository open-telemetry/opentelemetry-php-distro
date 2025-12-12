--TEST--
instrumentation - user method - placing hook with mixed case class and method name
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);


class TestClass {
  function Userspace($arg1, $arg2, $arg3) {
    echo "* userspace() body start.\n";
    echo "* userspace() body end\n";
    return "userspace_rv";
  }
}

\OpenTelemetry\Distro\hook("tEstcLass", "userSpace", function () {
	echo "*** prehook userspace()\n";
 }, function () {
	echo "*** posthook userspace()\n";
});

$obj = new TestClass;

var_dump($obj->Userspace("first", 2, 3));

echo "Test completed\n";
?>
--EXPECTF--
*** prehook userspace()
* userspace() body start.
* userspace() body end
*** posthook userspace()
string(12) "userspace_rv"
Test completed