--TEST--
instrumentation - user method - static
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);


class TestClass
{
  static function userspace($arg1, $arg2, $arg3)
  {
    echo "* userspace()\n";
  }
}

\OpenTelemetry\Distro\hook("testclass", "userspace", function () {
  echo "*** prehook userspace()\n";
}, function (): void {
  echo "*** posthook userspace()\n";
});


TestClass::userspace("first", 2, 3);

echo "Test completed\n";
?>
--EXPECTF--
*** prehook userspace()
* userspace()
*** posthook userspace()
Test completed