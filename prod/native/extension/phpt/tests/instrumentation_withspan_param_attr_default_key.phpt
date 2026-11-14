--TEST--
instrumentation - WithSpan - SpanAttribute on param uses param name as key by default
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
use OpenTelemetry\API\Instrumentation\SpanAttribute;

#[WithSpan]
function myFunc(
    #[SpanAttribute] string $userId,
    string $secret,
    #[SpanAttribute] string $operation
): void {}

myFunc("user123", "s3cr3t", "read");
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
  ["userId"]=>
  string(7) "user123"
  ["operation"]=>
  string(4) "read"
}
post:
  retval=NULL
  exception=NULL
done
