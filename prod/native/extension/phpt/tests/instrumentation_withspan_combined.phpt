--TEST--
instrumentation - WithSpan - combined: custom name/kind + param attrs + static attrs
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

class OrderService {
    #[SpanAttribute('tenant')]
    public string $tenantId = 'acme';

    #[WithSpan('order.process', 3, attributes: ['component' => 'billing'])]
    public function processOrder(
        #[SpanAttribute('order.id')] string $orderId,
        string $internalNote,
        #[SpanAttribute] string $currency
    ): string {
        echo "body\n";
        return "processed";
    }
}

$svc = new OrderService();
var_dump($svc->processOrder("ORD-99", "secret", "USD"));
echo "done\n";
?>
--EXPECT--
pre:
  target=object(OrderService)
  class=string(12) "OrderService"
  function=string(12) "processOrder"
  span_args=array(2) {
  ["name"]=>
  string(13) "order.process"
  ["span_kind"]=>
  int(3)
}
  attributes=array(4) {
  ["component"]=>
  string(7) "billing"
  ["order.id"]=>
  string(6) "ORD-99"
  ["currency"]=>
  string(3) "USD"
  ["tenant"]=>
  string(4) "acme"
}
body
post:
  retval=string(9) "processed"
  exception=NULL
string(9) "processed"
done
