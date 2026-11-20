--TEST--
instrumentation - WithSpan - instance method: target is $this, class is reported
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

class MyService {
    #[WithSpan]
    public function doWork(#[SpanAttribute] string $task): string {
        echo "body\n";
        return "done";
    }
}

var_dump((new MyService())->doWork("deploy"));
echo "done\n";
?>
--EXPECT--
pre:
  target=object(MyService)
  class=string(9) "MyService"
  function=string(6) "doWork"
  span_args=array(0) {
}
  attributes=array(1) {
  ["task"]=>
  string(6) "deploy"
}
body
post:
  retval=string(4) "done"
  exception=NULL
string(4) "done"
done
