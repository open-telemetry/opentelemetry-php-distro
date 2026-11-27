--TEST--
Check that SpanAttribute on a private property of the parent class is read when the method runs on a subclass
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip requires PHP >= 8.1"); ?>
--ENV--
OTEL_PHP_SCOPED_DEPS_ENABLED=false
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock_unscoped.inc
opentelemetry_distro.attr_hooks_enabled = On
--FILE--
<?php
namespace OpenTelemetry\API\Instrumentation;

include dirname(__DIR__) . '/mocks/WithSpan.php';
include dirname(__DIR__) . '/mocks/SpanAttribute.php';

class WithSpanHandler
{
    public static function pre($object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno, array $spanArgs, array $attributes): void
    {
        echo get_class($object), ' ', json_encode($attributes), "\n";
    }

    public static function post(): void
    {
    }
}

class P
{
    #[SpanAttribute]
    private string $id = 'parent-id';

    #[WithSpan]
    public function run(): void
    {
    }
}

class C extends P
{
}

(new P())->run();
(new C())->run();
?>
--EXPECT--
OpenTelemetry\API\Instrumentation\P {"id":"parent-id"}
OpenTelemetry\API\Instrumentation\C {"id":"parent-id"}
