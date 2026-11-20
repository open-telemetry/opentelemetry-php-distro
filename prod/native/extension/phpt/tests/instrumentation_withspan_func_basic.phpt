--TEST--
instrumentation - WithSpan - basic function, no params
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

#[WithSpan]
function myFunc(): string {
    echo "body\n";
    return "result";
}

var_dump(myFunc());
echo "done\n";
?>
--EXPECT--
pre:
  target=NULL
  class=NULL
  function=string(6) "myFunc"
  span_args=array(0) {
}
  attributes=array(0) {
}
body
post:
  retval=string(6) "result"
  exception=NULL
string(6) "result"
done
