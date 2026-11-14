--TEST--
instrumentation - WithSpan - custom span name and kind on function
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

// span_kind 3 = KIND_CLIENT (OTel SpanKind enum value)
#[WithSpan('custom.operation', 3)]
function myFunc(): void {}

myFunc();
echo "done\n";
?>
--EXPECT--
pre:
  target=NULL
  class=NULL
  function=string(6) "myFunc"
  span_args=array(2) {
  ["name"]=>
  string(16) "custom.operation"
  ["span_kind"]=>
  int(3)
}
  attributes=array(0) {
}
post:
  retval=NULL
  exception=NULL
done
