--TEST--
instrumentation - WithSpan - SpanAttribute on property reads value from $this at call time
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

class TenantService {
    #[SpanAttribute]
    public string $tenantId = '';

    #[SpanAttribute('env.name')]
    public string $environment = '';

    #[WithSpan]
    public function process(): void {
        echo "body\n";
    }
}

$svc = new TenantService();
$svc->tenantId = 'tenant42';
$svc->environment = 'production';
$svc->process();
echo "done\n";
?>
--EXPECT--
pre:
  target=object(TenantService)
  class=string(13) "TenantService"
  function=string(7) "process"
  span_args=array(0) {
}
  attributes=array(2) {
  ["tenantId"]=>
  string(8) "tenant42"
  ["env.name"]=>
  string(10) "production"
}
body
post:
  retval=NULL
  exception=NULL
done
