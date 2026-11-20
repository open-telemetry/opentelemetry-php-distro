--TEST--
instrumentation - WithSpan - static method: target is class name string
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

class MyService {
    #[WithSpan('my.static.op', 2)]
    public static function create(): string {
        echo "body\n";
        return "created";
    }
}

var_dump(MyService::create());
echo "done\n";
?>
--EXPECT--
pre:
  target=string(9) "MyService"
  class=string(9) "MyService"
  function=string(6) "create"
  span_args=array(2) {
  ["name"]=>
  string(12) "my.static.op"
  ["span_kind"]=>
  int(2)
}
  attributes=array(0) {
}
body
post:
  retval=string(7) "created"
  exception=NULL
string(7) "created"
done
