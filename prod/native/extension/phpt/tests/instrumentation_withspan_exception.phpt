--TEST--
instrumentation - WithSpan - post hook receives exception when function throws
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
function riskyOp(#[SpanAttribute] string $reason): void {
    throw new \RuntimeException("Deliberate: {$reason}");
}

try {
    riskyOp("test-failure");
} catch (\Throwable $e) {
    echo "caught: " . $e->getMessage() . "\n";
}
echo "done\n";
?>
--EXPECT--
pre:
  target=NULL
  class=NULL
  function=string(7) "riskyOp"
  span_args=array(0) {
}
  attributes=array(1) {
  ["reason"]=>
  string(12) "test-failure"
}
post:
  retval=NULL
  exception=RuntimeException: Deliberate: test-failure
caught: Deliberate: test-failure
done
