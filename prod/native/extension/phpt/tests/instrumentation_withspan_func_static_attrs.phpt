--TEST--
instrumentation - WithSpan - static attributes dict in attribute constructor
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=INFO
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
opentelemetry_distro.attr_hooks_enabled=1
--FILE--
<?php
declare(strict_types=1);

require __DIR__ . '/includes/withspan_handler_mock.inc';

use OpenTelemetry\API\Instrumentation\WithSpan;

#[WithSpan(attributes: ['env' => 'prod', 'version' => '2.0'])]
function myFunc(): void {}

myFunc();
echo "done\n";
?>
--EXPECT--
pre:
  target=NULL
  class=NULL
  function=string(6) "myFunc"
  span_args=array(0) {
}
  attributes=array(2) {
  ["env"]=>
  string(4) "prod"
  ["version"]=>
  string(3) "2.0"
}
post:
  retval=NULL
  exception=NULL
done
